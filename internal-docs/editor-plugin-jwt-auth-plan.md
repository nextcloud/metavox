# Plan: JWT-based Editor Plugin Authenticatie

## Probleem

De MetaVox editor plugin (Euro-Office/ONLYOFFICE) communiceert met de MetaVox API via een reverse proxy. Deze proxy is onveilig:
- Publiek toegankelijk zonder authenticatie
- Gebruikt een hardcoded admin app password
- Iedereen die de URL kent kan metadata lezen en schrijven

## Oplossing: ONLYOFFICE JWT hergebruiken

De ONLYOFFICE Nextcloud connector genereert een JWT token per document-sessie. Dit token:
- Is al beschikbaar in de plugin via `Asc.plugin.info.documentCallbackUrl` (als `doc` query parameter)
- Is gesigned met het gedeelde `JWT_SECRET` (HS256) tussen Nextcloud en de DocumentServer
- Bevat: `userId`, `ownerId`, `fileId`, `filePath`, `shareToken`, `action`

MetaVox kan dit JWT valideren en gebruiken als authenticatie — geen proxy, geen hardcoded credentials, per-gebruiker autorisatie.

## Architectuur

```
Plugin (browser, euro-office.example.com)
  → GET https://nextcloud.example.com/ocs/v2.php/apps/metavox/api/v1/editor/files/{fileId}/metadata?doc=<JWT>&format=json
  → MetaVox EditorController:
    1. Leest ONLYOFFICE jwt_secret uit oc_appconfig
    2. Valideert JWT met Firebase\JWT
    3. Checkt dat fileId in JWT matcht met request
    4. Retourneert metadata
  → Plugin rendert velden
```

### Waarom geen CORS preflight probleem?

Het JWT wordt als **query parameter** (`?doc=...`) meegestuurd, niet als custom header. Een GET request met alleen query parameters is een "simple request" — de browser stuurt geen OPTIONS preflight. De `#[CORS]` attribute op het endpoint zorgt voor de `Access-Control-Allow-Origin` header op de response.

### Waarom geen NC sessie nodig?

Het endpoint gebruikt `#[PublicPage]` — Nextcloud slaat sessie-authenticatie over. Het JWT IS de authenticatie.

## MetaVox wijzigingen

### 1. Nieuw bestand: `lib/Controller/EditorController.php`

OCS controller met JWT-validated endpoints:

```php
class EditorController extends OCSController {

    #[CORS]
    #[NoCSRFRequired]
    #[PublicPage]
    public function getFileMetadata(int $fileId, string $doc = ''): DataResponse {
        // 1. Valideer JWT
        $secret = $this->config->getAppValue('onlyoffice', 'jwt_secret', '');
        $payload = \Firebase\JWT\JWT::decode($doc, new Key($secret, 'HS256'));

        // 2. Check fileId match
        if ((int)$payload->fileId !== $fileId) → 403

        // 3. Haal metadata op via ApiFieldService facade
        return $this->apiFieldService->getFileMetadata($fileId);
    }
}
```

**Endpoints:**

| Method | Route | Functie |
|--------|-------|---------|
| GET | `/api/v1/editor/files/{fileId}/metadata` | File metadata ophalen |
| POST | `/api/v1/editor/files/{fileId}/metadata` | File metadata opslaan |
| GET | `/api/v1/editor/groupfolders/{gfId}/metadata` | Team folder metadata ophalen |
| GET | `/api/v1/editor/groupfolders` | Groupfolders lijst (voor GF ID detectie) |

**Attributes:**
- `#[PublicPage]` — geen NC login vereist
- `#[CORS]` — cross-origin headers
- `#[NoCSRFRequired]` — geen CSRF token

**JWT validatie:**
- Secret: `$config->getAppValue('onlyoffice', 'jwt_secret', '')` met fallback naar `$config->getSystemValue('secret')`
- Library: `firebase/jwt` (al beschikbaar via ONLYOFFICE connector dependency)
- Algorithm: HS256
- Payload check: `fileId` in JWT moet matchen met request fileId

### 2. Routes toevoegen: `appinfo/routes.php`

Vier nieuwe OCS routes onder `'ocs'` array.

### 3. Geen andere MetaVox bestanden gewijzigd

- Hergebruikt bestaande `ApiFieldService` facade (beta.3)
- Hergebruikt bestaande `FieldService::getGroupfolderMetadata()`
- Hergebruikt bestaande `FieldService::getGroupfolders()`

## Plugin wijzigingen

Na implementatie van de MetaVox endpoints:

1. Plugin stuurt requests direct naar Nextcloud (niet via proxy)
2. Nextcloud URL wordt gedetecteerd uit de callback URL origin
3. JWT token wordt meegestuurd als `?doc=` query parameter
4. Reverse proxy kan verwijderd worden

## Beveiligingsmodel

| Aspect | Huidig (proxy) | Nieuw (JWT) |
|--------|---------------|-------------|
| Authenticatie | Geen (proxy voegt hardcoded credentials toe) | ONLYOFFICE JWT per sessie |
| Autorisatie | Admin rechten voor iedereen | Per-gebruiker (userId uit JWT) |
| Publiek bereikbaar | Ja, iedereen kan metadata lezen/schrijven | Ja, maar alleen met geldig JWT |
| Token scope | N/A | Per document-sessie, verloopt met de editor sessie |
| Credential exposure | Admin app password in nginx config | Geen credentials in browser of config |

## Dependencies

- `firebase/jwt` PHP library — moet beschikbaar zijn in MetaVox. Check of het al geïnstalleerd is via de ONLYOFFICE connector, of voeg het toe aan MetaVox's `composer.json`.

## Volgorde

1. Check of `firebase/jwt` beschikbaar is in MetaVox's PHP runtime
2. Maak `EditorController.php` aan
3. Voeg routes toe aan `routes.php`
4. Deploy naar test-server
5. Test met curl: `curl "https://nextcloud.example.com/ocs/v2.php/apps/metavox/api/v1/editor/files/121442/metadata?doc=<JWT>&format=json"`
6. Pas plugin.js aan om direct naar NC te praten
7. Verwijder reverse proxy config
