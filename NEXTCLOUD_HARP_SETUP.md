# Nextcloud HaRP Installatie & Configuratie

## Overzicht

Deze documentatie beschrijft de volledige installatie en configuratie van Nextcloud HaRP (HAProxy Reverse Proxy) voor het draaien van External Apps (ExApps) op een aparte server.

**Datum**: 19 november 2025
**Status**: ✅ Werkend

---

## Infrastructuur Setup

### Servers

- **Nextcloud Server**: 145.38.193.69
  - URL: https://diwug.hvanextcloudpoc.src.surf-hosted.nl
  - OS: Debian/Ubuntu Linux
  - Webserver: Apache 2
  - Nextcloud versie: 32.0.1.2
  - App API versie: 32.0.0

- **HaRP Server**: 145.38.184.45
  - Docker versie: 29.0.2
  - Docker API versie: 1.52 (minimum: 1.44)
  - HaRP container: `ghcr.io/nextcloud/nextcloud-appapi-harp:release`
  - Network mode: host

### Architectuur

```
┌─────────────────────────────────────────────────────────────────┐
│                         Browser/Client                           │
└────────────────────────────┬────────────────────────────────────┘
                             │ HTTPS
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│         Nextcloud Server (145.38.193.69)                         │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ Apache Reverse Proxy                                      │   │
│  │  - /exapps/* → HaRP (145.38.184.45:8780)                │   │
│  │  - Adds harp-shared-key header                           │   │
│  └──────────────────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ Nextcloud + App API                                       │   │
│  │  - Manages ExApps                                         │   │
│  │  - Communicates with HaRP daemon                         │   │
│  └──────────────────────────────────────────────────────────┘   │
└────────────────────────────┬────────────────────────────────────┘
                             │ HTTP
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│            HaRP Server (145.38.184.45)                           │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ HaRP Container (host network)                             │   │
│  │  ┌────────────────────────────────────────────────────┐  │   │
│  │  │ HAProxy (port 8780)                                 │  │   │
│  │  │  - Routes /exapps/* to ExApp containers            │  │   │
│  │  │  - Routes /exapps/app_api/* to Docker socket       │  │   │
│  │  └────────────────────────────────────────────────────┘  │   │
│  │  ┌────────────────────────────────────────────────────┐  │   │
│  │  │ FRP Server (port 8782)                              │  │   │
│  │  │  - TLS enabled                                      │  │   │
│  │  │  - Manages tunnels from ExApps to Nextcloud        │  │   │
│  │  └────────────────────────────────────────────────────┘  │   │
│  │  ┌────────────────────────────────────────────────────┐  │   │
│  │  │ FRP Client (bundled-deploy-daemon)                  │  │   │
│  │  │  - Tunnels Docker socket to FRP server             │  │   │
│  │  │  - Port 24000                                       │  │   │
│  │  └────────────────────────────────────────────────────┘  │   │
│  └──────────────────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ Docker Engine                                             │   │
│  │  - Runs ExApp containers                                 │   │
│  │  - Each ExApp gets own FRP tunnel (port 23000+)         │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

---

## Problemen & Oplossingen

### Probleem 1: Docker API Versie Incompatibiliteit

**Foutmelding**:
```
Client error: `GET http://145.38.184.45:8780/exapps/app_api/v1.41/_ping` resulted in a `400 Bad Request` response:
{"message":"client version 1.41 is too old. Minimum supported API version is 1.44"}
```

**Oorzaak**:
- Docker Engine werd geüpgraded naar versie 29.0.2
- Docker 29.0.x vereist minimaal API versie 1.44
- Nextcloud App API had hardcoded versie 1.41

**Oplossing**:

Bestand aanpassen op Nextcloud server:

```bash
# SSH naar Nextcloud server
ssh sditmeijer@145.38.193.69

# Backup maken
sudo cp /var/www/nextcloud/apps/app_api/lib/DeployActions/DockerActions.php \
       /var/www/nextcloud/apps/app_api/lib/DeployActions/DockerActions.php.bak

# API versie aanpassen van v1.41 naar v1.44
sudo sed -i 's/v1\.41/v1.44/g' \
    /var/www/nextcloud/apps/app_api/lib/DeployActions/DockerActions.php

# Verificatie
sudo grep DOCKER_API_VERSION \
    /var/www/nextcloud/apps/app_api/lib/DeployActions/DockerActions.php

# Output moet zijn:
# public const DOCKER_API_VERSION = 'v1.44';

# App API herladen
sudo -u www-data php /var/www/nextcloud/occ app:disable app_api
sudo -u www-data php /var/www/nextcloud/occ app:enable app_api
```

**Let op**: Deze fix moet mogelijk opnieuw toegepast worden na een App API update.

---

### Probleem 2: HaRP Daemon Configuratie

**Probleem**: Geen Deploy Daemon geconfigureerd voor HaRP.

**Oplossing**: HaRP daemon registreren via occ command.

```bash
# SSH naar Nextcloud server
ssh sditmeijer@145.38.193.69

# HaRP daemon registreren
sudo -u www-data php /var/www/nextcloud/occ app_api:daemon:register \
  harp_docker \
  'HaRP Docker' \
  'docker-install' \
  'http' \
  '145.38.184.45:8780' \
  'https://diwug.hvanextcloudpoc.src.surf-hosted.nl' \
  --net host \
  --harp \
  --harp_frp_address '145.38.184.45:8782' \
  --harp_shared_key 'Amsterdam123!' \
  --set-default

# Verificatie
sudo -u www-data php /var/www/nextcloud/occ app_api:daemon:list
```

**Parameters uitleg**:
- `harp_docker`: Unieke daemon naam
- `'HaRP Docker'`: Display naam in UI
- `docker-install`: Deploy methode (vs manual-install)
- `http`: Protocol (HaRP ondersteunt HTTP op 8780)
- `145.38.184.45:8780`: HaRP host en poort
- `--net host`: Docker network mode
- `--harp`: Enables HaRP specifieke features
- `--harp_frp_address`: FRP server adres voor tunnels
- `--harp_shared_key`: Authenticatie key (moet matchen met HaRP config)
- `--set-default`: Maak dit de standaard daemon

---

### Probleem 3: ExApp Heartbeat Failures

**Foutmelding**:
```
ExApp test-deploy heartbeat check failed. Make sure that Nextcloud instance and ExApp can reach each other.
Failed heartbeat on https://diwug.../exapps/test-deploy for 60 times.
Client error: `GET https://diwug.../exapps/test-deploy/heartbeat` resulted in a `404 Not Found` response
```

**Oorzaak**:
- Nextcloud probeert ExApp te bereiken via zijn eigen publieke URL
- Route `/exapps/*` bestaat niet in Apache configuratie
- Requests moeten doorgestuurd worden naar HaRP server

**Oplossing**: Apache reverse proxy configureren.

#### Stap 1: Apache modules activeren

```bash
# SSH naar Nextcloud server
ssh sditmeijer@145.38.193.69

# Proxy modules activeren
sudo a2enmod proxy proxy_http proxy_wstunnel headers

# Apache configuratie testen
sudo apache2ctl -t
```

#### Stap 2: ExApps proxy configuratie aanmaken

Bestand maken: `/etc/apache2/conf-available/nextcloud-exapps.conf`

```bash
sudo tee /etc/apache2/conf-available/nextcloud-exapps.conf > /dev/null << 'EOF'
# Nextcloud ExApps Proxy Configuration
# Proxy requests to /exapps/* to HaRP server

<Location /exapps>
    ProxyPass http://145.38.184.45:8780/exapps
    ProxyPassReverse http://145.38.184.45:8780/exapps

    # Add HaRP shared key header for authentication
    RequestHeader set "harp-shared-key" "Amsterdam123!"

    # Preserve original headers
    ProxyPreserveHost On

    # WebSocket support for real-time communication
    RewriteEngine on
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/exapps/(.*)  ws://145.38.184.45:8780/exapps/$1 [P,L]
</Location>
EOF
```

#### Stap 3: Configuratie activeren

```bash
# Configuratie activeren
sudo a2enconf nextcloud-exapps

# Apache configuratie testen
sudo apache2ctl -t

# Apache herladen
sudo systemctl reload apache2

# Verificatie
sudo apache2ctl -M | grep proxy
```

**Verwachte output modules**:
```
proxy_module (shared)
proxy_http_module (shared)
proxy_wstunnel_module (shared)
```

---

## HaRP Server Configuratie

### Docker Compose Setup

Bestand: `~/harp/docker-compose.yml`

```yaml
services:
  nextcloud-appapi-harp:
    environment:
      - HP_SHARED_KEY=Amsterdam123!
      - NC_INSTANCE_URL=https://diwug.hvanextcloudpoc.src.surf-hosted.nl
      - HP_EXAPPS_ADDRESS=0.0.0.0:8780
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - ./certs:/certs
    container_name: appapi-harp
    hostname: appapi-harp
    restart: unless-stopped
    network_mode: host
    image: ghcr.io/nextcloud/nextcloud-appapi-harp:release

networks: {}
```

### HaRP Starten

```bash
# SSH naar HaRP server
ssh sditmeijer@145.38.184.45

# Naar HaRP directory
cd ~/harp

# HaRP starten
sudo docker compose up -d

# Logs bekijken
sudo docker logs appapi-harp

# Status controleren
sudo docker ps | grep harp
```

### FRP Certificaten

HaRP genereert automatisch TLS certificaten voor FRP communicatie:

```bash
# Certificaten locatie
~/harp/certs/frp/
├── ca.crt              # Certificate Authority
├── ca.key              # CA private key
├── client.crt          # FRP client certificaat
├── client.key          # FRP client private key
├── server.crt          # FRP server certificaat
└── server.key          # FRP server private key
```

Deze certificaten worden automatisch gebruikt door:
- FRP server (poort 8782)
- ExApp containers (FRP clients)

---

## Communicatie Flows

### 1. Nextcloud → ExApp (Heartbeat/API)

```
User Browser
    ↓ HTTPS
Nextcloud (145.38.193.69)
    ↓ Apache Reverse Proxy (/exapps/*)
    ↓ HTTP + harp-shared-key header
HaRP Server (145.38.184.45:8780)
    ↓ HAProxy routing
    ↓ FRP tunnel lookup
ExApp Container (via FRP port 23000+)
```

**Request flow**:
1. Browser → `https://diwug.../exapps/test-deploy/heartbeat`
2. Apache → `http://145.38.184.45:8780/exapps/test-deploy/heartbeat`
3. HAProxy → FRP tunnel → ExApp container

### 2. ExApp → Nextcloud (Callbacks)

```
ExApp Container
    ↓ FRP Client (TLS)
FRP Server (145.38.184.45:8782)
    ↓ FRP Tunnel
HAProxy
    ↓ HTTP(S)
Nextcloud (https://diwug....)
```

**Request flow**:
1. ExApp maakt FRP tunnel connectie met TLS
2. FRP server routeert traffic naar Nextcloud URL
3. Nextcloud ontvangt callback van ExApp

### 3. Nextcloud → Docker (Deployment)

```
Nextcloud App API
    ↓ HTTP + harp-shared-key
HaRP (145.38.184.45:8780/exapps/app_api/v1.44/*)
    ↓ HAProxy routing
FRP Tunnel (port 24000)
    ↓
Docker Engine (/var/run/docker.sock)
```

**Deployment flow**:
1. Nextcloud → Pull image via HaRP
2. Nextcloud → Create container via HaRP
3. Nextcloud → Start container via HaRP
4. Container → Establish FRP tunnel
5. Nextcloud → Verify heartbeat via HaRP

---

## Poorten Overzicht

### HaRP Server (145.38.184.45)

| Poort | Protocol | Doel | Toegankelijk van |
|-------|----------|------|------------------|
| 8780 | HTTP | HaRP HAProxy frontend | Nextcloud server |
| 8781 | HTTPS | HaRP HAProxy frontend (optioneel) | Nextcloud server |
| 8782 | TCP/TLS | FRP server voor tunnels | ExApp containers |
| 23000-23999 | TCP | FRP tunnel poorten voor ExApps | Intern (via FRP) |
| 24000-24099 | TCP | FRP tunnel poorten voor Docker socket | Intern (via FRP) |

### Nextcloud Server (145.38.193.69)

| Poort | Protocol | Doel |
|-------|----------|------|
| 443 | HTTPS | Nextcloud web interface |
| 80 | HTTP | Redirect naar HTTPS |

---

## Verificatie & Troubleshooting

### HaRP Status Controleren

```bash
# Container status
ssh sditmeijer@145.38.184.45
sudo docker ps | grep harp

# Logs bekijken
sudo docker logs appapi-harp --tail 100

# FRP server logs
sudo docker exec appapi-harp tail -100 /frps.log

# Healthcheck
sudo docker inspect appapi-harp | grep -A 10 Health
```

### Daemon Status in Nextcloud

```bash
ssh sditmeijer@145.38.193.69

# Lijst daemons
sudo -u www-data php /var/www/nextcloud/occ app_api:daemon:list

# Lijst ExApps
sudo -u www-data php /var/www/nextcloud/occ app_api:app:list
```

### Test HaRP Connectiviteit

```bash
# Van Nextcloud server naar HaRP
ssh sditmeijer@145.38.193.69

# Test HaRP endpoint
curl -H "harp-shared-key: Amsterdam123!" \
     http://145.38.184.45:8780/exapps/app_api/v1.44/_ping

# Verwacht: OK of Docker ping response
```

### ExApp Container Logs

```bash
# SSH naar HaRP server
ssh sditmeijer@145.38.184.45

# Bekijk running ExApps
sudo docker ps | grep nc_app_

# Logs van specifieke ExApp
sudo docker logs nc_app_test-deploy

# Real-time logs volgen
sudo docker logs -f nc_app_test-deploy
```

### Apache Proxy Status

```bash
ssh sditmeijer@145.38.193.69

# Check Apache modules
sudo apache2ctl -M | grep proxy

# Check configuration
sudo apache2ctl -t

# Check enabled configs
ls -la /etc/apache2/conf-enabled/ | grep exapps

# Apache error log
sudo tail -50 /var/log/apache2/error.log
```

### Netwerk Connectiviteit Testen

```bash
# Van Nextcloud naar HaRP
ssh sditmeijer@145.38.193.69
nc -zv 145.38.184.45 8780
nc -zv 145.38.184.45 8782

# Van HaRP server lokaal
ssh sditmeijer@145.38.184.45
nc -zv 127.0.0.1 8780
nc -zv 127.0.0.1 8782
```

---

## ExApp Installatie

### Via Web Interface

1. Log in op Nextcloud als admin
2. Ga naar **Apps** → **External Apps** (of **App API**)
3. Klik op **Deploy test app** of zoek naar gewenste ExApp
4. Selecteer **harp_docker** daemon
5. Klik op **Deploy**
6. Wacht tot deployment compleet is
7. ExApp verschijnt in het apps menu

### Via Command Line

```bash
ssh sditmeijer@145.38.193.69

# Installeer test-deploy ExApp
sudo -u www-data php /var/www/nextcloud/occ app_api:app:register \
  test-deploy \
  harp_docker \
  --wait-finish

# Check status
sudo -u www-data php /var/www/nextcloud/occ app_api:app:list

# Verwijder ExApp
sudo -u www-data php /var/www/nextcloud/occ app_api:app:unregister test-deploy
```

---

## Backup & Restore

### Backup Maken

```bash
# HaRP configuratie backup
ssh sditmeijer@145.38.184.45
tar -czf harp-backup-$(date +%Y%m%d).tar.gz ~/harp/

# Nextcloud App API fix backup
ssh sditmeijer@145.38.193.69
sudo cp /var/www/nextcloud/apps/app_api/lib/DeployActions/DockerActions.php \
       ~/DockerActions.php.backup-$(date +%Y%m%d)

# Apache configuratie backup
sudo cp /etc/apache2/conf-available/nextcloud-exapps.conf \
       ~/nextcloud-exapps.conf.backup-$(date +%Y%m%d)
```

### Restore

```bash
# HaRP restore
ssh sditmeijer@145.38.184.45
tar -xzf harp-backup-20251119.tar.gz -C ~/

# Nextcloud fix restore
ssh sditmeijer@145.38.193.69
sudo cp ~/DockerActions.php.backup-20251119 \
       /var/www/nextcloud/apps/app_api/lib/DeployActions/DockerActions.php
sudo -u www-data php /var/www/nextcloud/occ app:disable app_api
sudo -u www-data php /var/www/nextcloud/occ app:enable app_api

# Apache config restore
sudo cp ~/nextcloud-exapps.conf.backup-20251119 \
       /etc/apache2/conf-available/nextcloud-exapps.conf
sudo systemctl reload apache2
```

---

## Onderhoud

### App API Update

Na een App API update moet de Docker API versie fix mogelijk opnieuw toegepast worden:

```bash
ssh sditmeijer@145.38.193.69

# Check huidige versie
sudo grep DOCKER_API_VERSION \
    /var/www/nextcloud/apps/app_api/lib/DeployActions/DockerActions.php

# Als v1.41, opnieuw fixen:
sudo sed -i 's/v1\.41/v1.44/g' \
    /var/www/nextcloud/apps/app_api/lib/DeployActions/DockerActions.php

# App herladen
sudo -u www-data php /var/www/nextcloud/occ app:disable app_api
sudo -u www-data php /var/www/nextcloud/occ app:enable app_api
```

### HaRP Update

```bash
ssh sditmeijer@145.38.184.45
cd ~/harp

# Pull nieuwste image
sudo docker compose pull

# Recreate container
sudo docker compose up -d

# Verificatie
sudo docker logs appapi-harp
```

### Logs Cleanup

```bash
# HaRP logs cleanup
ssh sditmeijer@145.38.184.45
sudo docker exec appapi-harp sh -c "truncate -s 0 /frps.log"

# Oude ExApp containers verwijderen
sudo docker container prune -f
```

---

## Security Overwegingen

### Shared Key Beveiliging

De HaRP shared key (`Amsterdam123!`) wordt gebruikt voor:
- Authenticatie tussen Nextcloud en HaRP
- Headers in Apache reverse proxy
- Deployment requests

**Aanbevelingen**:
- Gebruik een sterke, willekeurige key (32+ tekens)
- Bewaar de key veilig (password manager)
- Roteer de key periodiek

### Firewall Regels

**HaRP Server (145.38.184.45)**:
```bash
# Alleen Nextcloud server mag verbinden met HaRP
sudo ufw allow from 145.38.193.69 to any port 8780 proto tcp
sudo ufw allow from 145.38.193.69 to any port 8782 proto tcp

# Block other incoming connections
sudo ufw default deny incoming
sudo ufw default allow outgoing
```

### TLS/SSL

- **Nextcloud → HaRP**: HTTP (binnen trusted network)
  - Kan verbeterd worden met HTTPS door certificaat toe te voegen aan HaRP
- **FRP Tunnels**: TLS enabled (automatisch via certificates)
- **ExApp → Nextcloud**: HTTPS (via publieke URL)

---

## Referenties

- **Nextcloud App API**: https://github.com/nextcloud/app_api
- **HaRP**: https://github.com/nextcloud/HaRP
- **Nextcloud Docs**: https://docs.nextcloud.com/server/stable/admin_manual/exapps_management/
- **Docker API**: https://docs.docker.com/engine/api/

---

## Changelog

| Datum | Wijziging |
|-------|-----------|
| 2025-11-19 | Initiele installatie en configuratie |
| 2025-11-19 | Docker API versie fix toegepast (v1.41 → v1.44) |
| 2025-11-19 | Apache reverse proxy configuratie toegevoegd |
| 2025-11-19 | test-deploy ExApp succesvol geïnstalleerd |

---

## Contact & Support

Bij problemen:
1. Check logs (HaRP, Nextcloud, Apache)
2. Verifieer daemon status
3. Test connectiviteit tussen servers
4. Raadpleeg deze documentatie voor troubleshooting

**Setup door**: Claude Code Assistant
**Datum**: 19 november 2025
**Status**: ✅ Production Ready
