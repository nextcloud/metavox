# Real-time metadata-sync & cell-locking

MetaVox ondersteunt real-time metadata-synchronisatie tussen gebruikers via Nextcloud's `notify_push`-app. Wanneer één gebruiker metadata bewerkt, zien de andere gebruikers die dezelfde teammap open hebben in de Bestanden-app de wijziging direct. Bovendien voorkomt cell-locking gelijktijdige edits op hetzelfde veld.

![Twee gebruikers werken tegelijk in dezelfde metadata-kolommen — wijzigingen verschijnen direct bij de ander](../../screenshots/columns-collaboration.png)

## Vereisten

- **Nextcloud notify_push**-app geïnstalleerd en geconfigureerd
- **Redis** geconfigureerd als gedistribueerde cache in Nextcloud (notify_push heeft het nodig; aanwezigheid en cell-locks staan er ook in)
- **WebSocket reverse proxy** geconfigureerd in Apache/Nginx
- **Gebruikersstatus**-app ingeschakeld (aanbevolen): de heartbeat ervan houdt bij wie een teammap open heeft, zie [Aanwezigheid](#aanwezigheid)

**Beheerinstellingen → Overzicht** toont de setup-checks van MetaVox: "MetaVox live-updates" (notify_push), "MetaVox-aanwezigheid" (Gebruikersstatus-app) en "MetaVox gedeelde cache" (gedistribueerde cache).

### Installatie

```bash
# notify_push installeren
sudo -u www-data php /var/www/nextcloud/occ app:install notify_push

# Binary kopiëren
sudo cp /var/www/nextcloud/apps/notify_push/bin/x86_64/notify_push /usr/local/bin/notify_push
sudo chmod +x /usr/local/bin/notify_push

# systemd-service aanmaken
sudo tee /etc/systemd/system/notify_push.service << 'EOF'
[Unit]
Description=Nextcloud Push Notification Service
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/usr/local/bin/notify_push /var/www/nextcloud/config/config.php
Restart=always
RestartSec=5
Environment=PORT=7867

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now notify_push

# Apache reverse proxy
sudo tee /etc/apache2/conf-available/notify_push.conf << 'EOF'
ProxyPass /push/ws ws://127.0.0.1:7867/ws
ProxyPass /push/ http://127.0.0.1:7867/
ProxyPassReverse /push/ http://127.0.0.1:7867/
EOF

sudo a2enmod proxy proxy_http proxy_wstunnel
sudo a2enconf notify_push
sudo systemctl restart apache2

# Configureren
sudo -u www-data php /var/www/nextcloud/occ config:system:set trusted_proxies 0 --value='127.0.0.1'
sudo -u www-data php /var/www/nextcloud/occ config:app:set notify_push base_endpoint --value='https://your-domain.com/push'

# Verifiëren
sudo -u www-data php /var/www/nextcloud/occ notify_push:self-test
```

Alle checks moeten ✓ tonen.

---

## Hoe het werkt

### Real-time metadata-sync

```
Gebruiker A slaat metadata op
    ↓
FieldService::saveValues()
    ↓
1. Database INSERT/UPDATE
2. Cacheversie van de teammap opgehoogd, zoekwoorden bijgewerkt
3. Gewijzigde bestands-id's verzameld; aan het eind van het request
   gaat per teammap één push-event via Redis naar de notify_push-daemon,
   gericht aan elke gebruiker die die teammap open heeft
    ↓
notify_push-daemon levert af via WebSocket
    ↓
Browser van gebruiker B ontvangt push-event (alleen bestands-id's, geen waarden)
    ↓
MetaVox haalt de gewijzigde bestanden die hij toont opnieuw op via de API
(die per bestand de toegang van gebruiker B controleert)
    ↓
Cellen werken bij in de grid-view van gebruiker B
```

### Aanwezigheid

Pushes gaan alleen naar gebruikers die het grid van die teammap open hebben. Is er niemand, dan gaat er geen push uit; wie de map later opent, laadt verse waarden.

- **Binnenkomen**: een map van de teammap openen in de Bestanden-app registreert de gebruiker. Geen extra request.
- **Blijven**: de eigen status-heartbeat van Nextcloud (Gebruikersstatus-app, elke 5 minuten zolang een tabblad open is) houdt de registratie in leven. MetaVox stuurt geen eigen heartbeat. Een registratie vervalt 15 minuten na de laatste heartbeat.
- **Weggaan**: naar een andere teammap gaan of het tabblad sluiten verwijdert de registratie (`POST /api/presence/leave`).
- **Zonder de Gebruikersstatus-app** is er geen heartbeat, dus blijft een registratie 12 uur staan. Pushes bereiken dan ook gebruikers die weggingen zonder het tabblad te sluiten.

Aanwezigheid staat in de gedistribueerde cache van Nextcloud. Met meer dan één webserver moet die cache gedeeld zijn (Redis); APCu is per server.

### Cell-locking

![Cell-lock-indicator](../../screenshots/cell-lock-detail.png)

```
Gebruiker A dubbel-klikt een cel
    ↓
POST /api/groupfolders/{gfId}/files/{fileId}/lock
    ↓
Cache SET met 30s TTL: gf_{gfId}:{fileId}:{fieldName} = userId
    ↓
Push-event "metavox_cell_locked" naar de gebruikers die de teammap open hebben
    ↓
Gebruiker B ziet:
  - Oranje achtergrond-tint op de cel
  - Oranje inset-border (2px)
  - Cursor: not-allowed
  - Een badge boven de cel: "🔒 {gebruikers-id}"
  - Dubbel-klik geblokkeerd
    ↓
Gebruiker A sluit de editor (opslaan of annuleren)
    ↓
POST /api/groupfolders/{gfId}/files/{fileId}/unlock
    ↓
Redis-key verwijderd + push-event "metavox_cell_unlocked"
    ↓
Cel van gebruiker B keert terug naar normaal
```

### Crash-safety

Als gebruiker A het browsertabblad sluit of crasht zonder te ontgrendelen:

- De Redis lock-key heeft een **30-seconden TTL** en verloopt automatisch
- Geen stale locks — de cel is na 30 seconden weer beschikbaar
- Geen handmatige interventie nodig

De lock wordt niet verlengd zolang de editor open staat: ook dan verloopt hij 30 seconden nadat de editor openging.

Een gebruiker die een gelockte cel probeert te bewerken zonder de push te hebben gezien, krijgt een 409 van de lock-API; de cel toont dan 3 seconden "Wordt bewerkt door {user}" en de editor opent niet.

---

## Technische details

### Push-event-format

MetaVox gebruikt het `notify_custom` Redis-channel. De notify_push-daemon routet events naar specifieke gebruikers:

```php
// PHP: één bericht per gebruiker die de teammap open heeft
$this->notifyQueue->push('notify_custom', [
    'user' => $userId,
    'message' => 'metavox_metadata_changed',
    'body' => [
        'gfId' => $groupfolderId,
        'fileIds' => [456, 457],
    ],
]);
```

Het WebSocket-bericht arriveert als: `metavox_metadata_changed {"gfId":103,"fileIds":[456,457]}`

Een metadata-event bevat geen waarden, alleen de id's van de bestanden die in dat request zijn gewijzigd. De browser haalt de waarden zelf op, zodat een gebruiker alleen waarden ziet van bestanden die hij mag lezen. Meerdere saves in één request (bulkbewerking, standaardwaarden) worden één event per teammap.

Lock-events bevatten de cel: `metavox_cell_locked {"gfId":103,"fileId":456,"fieldName":"status","userId":"alice"}`; `metavox_cell_unlocked` heeft dezelfde body zonder `userId`. Ze gaan direct uit, niet aan het eind van het request.

### WebSocket-message-parsing

Nextcloud's ingebouwde push-client strip't de JSON-body bij dispatch naar `_notify_push_listeners`. MetaVox onderschept de raw WebSocket `onmessage`-handler om het volledige bericht inclusief body te parsen:

```javascript
ws.onmessage = (event) => {
    const raw = event.data  // "metavox_metadata_changed {"gfId":103,"fileIds":[456,457]}"
    const spaceIdx = raw.indexOf(' ')
    const eventName = raw.substring(0, spaceIdx)  // "metavox_metadata_changed"
    const body = JSON.parse(raw.substring(spaceIdx + 1))  // {gfId: 103, fileIds: [456, 457]}
}
```

### Lock-API

| Methode | Endpoint | Body | Response |
|---------|----------|------|----------|
| POST | `/api/groupfolders/{gfId}/files/{fileId}/lock` | `{field_name: "doc_title"}` | `{locked: false}` (verkregen) of `409 {locked: true, lockedBy: "user"}` |
| POST | `/api/groupfolders/{gfId}/files/{fileId}/unlock` | `{field_name: "doc_title"}` | `{success: true}` |

### Lock-opslag

Locks worden opgeslagen in de gedistribueerde cache van Nextcloud (Redis), in de cache `metavox_locks`:

- **Key**: `gf_{groupfolderId}:{fileId}:{fieldName}`
- **Value**: `userId`
- **TTL**: 30 seconden (auto-verloopt)

---

## Performance & schaalbaarheid

### Push-events

| Metric | Waarde | Notities |
|--------|--------|----------|
| Event-grootte | ~100 bytes voor één bestand | JSON met gfId + fileIds; groeit met het aantal bestanden dat in het request is gewijzigd |
| Redis PUBLISH-latency | ~0.1ms per gebruiker | Constant, ongeacht payload-grootte |
| WebSocket-delivery | ~10ms | Afhankelijk van netwerk-latency |
| 100 gebruikers met de teammap open | ~10ms totaal | 100 Redis PUBLISH-calls |
| 2.000 gebruikers met de teammap open | ~200ms totaal | 2000 Redis PUBLISH-calls |
| Teammap door niemand open | 0 | Er gaat geen push uit |

Leden die de teammap niet open hebben, ontvangen niets, hoe groot het ledental van de teammap ook is.

### Cell-locking

| Operatie | Backend-kosten | Netwerk-kosten |
|----------|---------------|-----------------|
| Lock verwerven | 1 cache-GET + 1 cache-SET | 1 POST-request |
| Lock-check (andere gebruiker probeert) | 1 cache-GET | 1 POST-request (409-response) |
| Unlock | 1 cache-GET + 1 cache-DELETE | 1 POST-request |
| Push-notificatie per lock/unlock | Zelfde als push-events | WebSocket (geen HTTP) |

### Gelijktijdig-bewerken-scenario's

| Scenario | Gedrag |
|----------|--------|
| 2 gebruikers, zelfde cel | Tweede gebruiker ziet lock-indicator, kan niet bewerken |
| 2 gebruikers, verschillende cellen | Beide bewerken onafhankelijk, zien elkaars wijzigingen real-time |
| 50 gebruikers, zelfde folder | Allen zien metadata-wijzigingen direct, locks voorkomen conflicten |
| Gebruiker crasht tijdens edit | Lock verloopt na 30s, cel wordt beschikbaar |
| notify_push niet geïnstalleerd | Graceful fallback — geen real-time sync, handmatig vernieuwen nodig; locks weigeren nog steeds een tweede bewerker |

### Redis-geheugen-gebruik

- Elke lock: ~100 bytes (key + value)
- 1.000 gelijktijdige edits: ~100 KB
- Locks verlopen na 30s — geen accumulatie

### WebSocket-connecties

notify_push gebruikt één Rust-daemon die alle connecties afhandelt:

- Geheugen: ~5 KB per verbonden browser
- 10.000 verbonden browsers: ~50 MB RAM
- CPU: verwaarloosbaar (alleen event-routing)

---

## Graceful degradation

Als `notify_push` niet is geïnstalleerd:

- **Geen real-time sync** — gebruikers moeten vernieuwen om elkaars wijzigingen te zien
- **Cell-locking werkt nog, zonder indicator** — locks staan in de gedistribueerde cache, niet in notify_push. Een tweede gebruiker ziet geen lock-badge, maar krijgt bij het bewerken van de cel "Wordt bewerkt door {user}"
- **Geen fouten** — alle push-gerelateerde code handelt ontbrekend `notify_push` graceful af
- **Volledige functionaliteit anders** — inline-bewerken, weergaven, filters werken normaal

Zonder enige gedistribueerde cache (geen `memcache.distributed` en geen lokale cache om op terug te vallen) worden locks niet bewaard en verleent de lock-API ze altijd.

De PushService-constructor wikkelt de `IQueue`-resolutie in een try-catch:

```php
try {
    $this->notifyQueue = \OCP\Server::get(\OCA\NotifyPush\Queue\IQueue::class);
} catch (\Throwable $e) {
    // notify_push niet geïnstalleerd: push is best effort
}
```

---

## Problemen oplossen

### Push-events arriveren niet

1. Check notify_push self-test: `occ notify_push:self-test` — alle checks moeten ✓ zijn
2. Check dat de ontvangende gebruiker de teammap open heeft in de Bestanden-app: pushes gaan alleen naar gebruikers die als aanwezig geregistreerd staan
3. Check WebSocket-connectie in browser: `console.log(window._notify_push_ws?.readyState)` — moet `1` (OPEN) zijn
4. Check listener-registratie: `console.log(window._notify_push_ws?._metavoxPatched)` — moet `true` zijn
5. Check daemon-logs: `journalctl -u notify_push -f`

### Lock-indicator wordt niet getoond

1. Verifieer dat het push-event arriveert: onderschep WebSocket met `ws.onmessage`-logging
2. Check of cel-selector matches: `document.querySelector('.metavox-col[data-file-id="123"]')`
3. Zorg dat twee verschillende gebruikers testen (eigen locks worden verborgen)

### Stale data na edit

- Server-side per-file-cache is verwijderd — alle metadata-reads gaan direct naar de database
- De Bestanden-app krijgt de waarden mee met de maplijst; opnieuw naar de map gaan, of vernieuwen, laadt ze vers
- Een lokale wijziging wint 60 seconden lang van oudere data, zodat een maplijst die tijdens het opslaan onderweg was de oude waarde niet terugbrengt
