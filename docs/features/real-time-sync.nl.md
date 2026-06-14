# Real-time metadata-sync & cell-locking

MetaVox ondersteunt real-time metadata-synchronisatie tussen gebruikers via Nextcloud's `notify_push`-app. Wanneer één gebruiker metadata bewerkt, zien alle andere gebruikers van dezelfde team folder de wijziging direct. Bovendien voorkomt cell-locking gelijktijdige edits op hetzelfde veld.

![Twee gebruikers werken tegelijk in dezelfde metadata-kolommen — wijzigingen verschijnen direct bij de ander](../screenshots/columns-collaboration.png)

## Vereisten

- **Nextcloud notify_push**-app geïnstalleerd en geconfigureerd
- **Redis** geconfigureerd als gedistribueerde cache in Nextcloud
- **WebSocket reverse proxy** geconfigureerd in Apache/Nginx

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
FieldService::saveGroupfolderFileFieldValue()
    ↓
1. Database INSERT/UPDATE
2. Server-side cache geïnvalideerd
3. Push-event verzonden via Redis naar notify_push-daemon
    ↓
notify_push-daemon broadcast via WebSocket
    ↓
Browser van gebruiker B ontvangt push-event
    ↓
MetaVox haalt ALLEEN de metadata van het gewijzigde bestand opnieuw op (1 API-call)
    ↓
Cel werkt direct bij in grid-view van gebruiker B
```

### Cell-locking

![Cell-lock-indicator](../screenshots/cell-lock-detail.png)

```
Gebruiker A dubbel-klikt een cel
    ↓
POST /api/groupfolders/{gfId}/files/{fileId}/lock
    ↓
Redis SET met 30s TTL: metavox_lock:{gfId}:{fileId}:{fieldName} = userId
    ↓
Push-event "metavox_cell_locked" naar alle groupfolder-leden
    ↓
Gebruiker B ziet:
  - Oranje achtergrond-tint op de cel
  - Oranje inset-border (2px)
  - Cursor: not-allowed
  - Hover-tooltip: "🔒 Wordt bewerkt door {username}"
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

---

## Technische details

### Push-event-format

MetaVox gebruikt het `notify_custom` Redis-channel. De notify_push-daemon routet events naar specifieke gebruikers:

```php
// PHP: verstuur naar elk groupfolder-lid
$this->notifyQueue->push('notify_custom', [
    'user' => $userId,
    'message' => 'metavox_metadata_changed',
    'body' => [
        'gfId' => $groupfolderId,
        'fileId' => $fileId,
    ],
]);
```

Het WebSocket-bericht arriveert als: `metavox_metadata_changed {"gfId":103,"fileId":456}`

### WebSocket-message-parsing

Nextcloud's ingebouwde push-client strip't de JSON-body bij dispatch naar `_notify_push_listeners`. MetaVox onderschept de raw WebSocket `onmessage`-handler om het volledige bericht inclusief body te parsen:

```javascript
ws.onmessage = (event) => {
    const raw = event.data  // "metavox_metadata_changed {"gfId":103,"fileId":456}"
    const spaceIdx = raw.indexOf(' ')
    const eventName = raw.substring(0, spaceIdx)  // "metavox_metadata_changed"
    const body = JSON.parse(raw.substring(spaceIdx + 1))  // {gfId: 103, fileId: 456}
}
```

### Lock-API

| Methode | Endpoint | Body | Response |
|---------|----------|------|----------|
| POST | `/api/groupfolders/{gfId}/files/{fileId}/lock` | `{field_name: "doc_title"}` | `{locked: false}` (verkregen) of `409 {locked: true, lockedBy: "user"}` |
| POST | `/api/groupfolders/{gfId}/files/{fileId}/unlock` | `{field_name: "doc_title"}` | `{success: true}` |

### Lock-opslag

Locks worden opgeslagen in Redis via Nextcloud's gedistribueerde cache:

- **Key**: `metavox_lock:{groupfolderId}:{fileId}:{fieldName}`
- **Value**: `userId`
- **TTL**: 30 seconden (auto-verloopt)

---

## Performance & schaalbaarheid

### Push-events

| Metric | Waarde | Notities |
|--------|--------|----------|
| Event-grootte | ~100 bytes | JSON met gfId + fileId |
| Redis PUBLISH-latency | ~0.1ms per gebruiker | Constant, ongeacht payload-grootte |
| WebSocket-delivery | ~10ms | Afhankelijk van netwerk-latency |
| 100 groupfolder-leden | ~10ms totaal | 100 Redis PUBLISH-calls |
| 2.000 groupfolder-leden | ~200ms totaal | 2000 Redis PUBLISH-calls |
| 10.000 groupfolder-leden | ~1s totaal | Binnen Redis-capaciteit |

### Cell-locking

| Operatie | Backend-kosten | Netwerk-kosten |
|----------|---------------|-----------------|
| Lock verwerven | 1 Redis GET + 1 Redis SET | 1 POST-request |
| Lock-check (andere gebruiker probeert) | 1 Redis GET | 1 POST-request (409-response) |
| Unlock | 1 Redis GET + 1 Redis DELETE | 1 POST-request |
| Push-notificatie per lock/unlock | Zelfde als push-events | WebSocket (geen HTTP) |

### Gelijktijdig-bewerken-scenario's

| Scenario | Gedrag |
|----------|--------|
| 2 gebruikers, zelfde cel | Tweede gebruiker ziet lock-indicator, kan niet bewerken |
| 2 gebruikers, verschillende cellen | Beide bewerken onafhankelijk, zien elkaars wijzigingen real-time |
| 50 gebruikers, zelfde folder | Allen zien metadata-wijzigingen direct, locks voorkomen conflicten |
| Gebruiker crasht tijdens edit | Lock verloopt na 30s, cel wordt beschikbaar |
| notify_push niet geïnstalleerd | Graceful fallback — geen real-time sync, handmatig vernieuwen nodig |

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
- **Geen cell-locking** — de lock-API-call slaagt stilzwijgend (geen Redis = geen lock-opslag)
- **Geen fouten** — alle push-gerelateerde code handelt ontbrekend `notify_push` graceful af
- **Volledige functionaliteit anders** — inline-bewerken, weergaven, filters werken normaal

De FieldService-constructor wikkelt de `IQueue`-resolutie in een try-catch:

```php
try {
    $this->notifyQueue = \OC::$server->get(\OCA\NotifyPush\Queue\IQueue::class);
} catch (\Exception $e) {
    // notify_push niet geïnstalleerd — real-time sync uitgeschakeld
}
```

---

## Problemen oplossen

### Push-events arriveren niet

1. Check notify_push self-test: `occ notify_push:self-test` — alle checks moeten ✓ zijn
2. Check WebSocket-connectie in browser: `console.log(window._notify_push_ws?.readyState)` — moet `1` (OPEN) zijn
3. Check listener-registratie: `console.log(window._notify_push_ws?._metavoxPatched)` — moet `true` zijn
4. Check daemon-logs: `journalctl -u notify_push -f`

### Lock-indicator wordt niet getoond

1. Verifieer dat het push-event arriveert: onderschep WebSocket met `ws.onmessage`-logging
2. Check of cel-selector matches: `document.querySelector('.metavox-col[data-file-id="123"]')`
3. Zorg dat twee verschillende gebruikers testen (eigen locks worden verborgen)

### Stale data na edit

- Server-side per-file-cache is verwijderd — alle metadata-reads gaan direct naar de database
- Als data stale lijkt, check Redis-cache-TTL op de filter-values-cache (300s)
