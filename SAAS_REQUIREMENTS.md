# Hubbee SaaS - Anforderungen für Plugin-Kompatibilität

## Übersicht

Das **Hubbee** WordPress-Plugin (v2.0.0) ist ein Agent-Only Plugin, das sich mit einer zentralen SaaS-Plattform verbindet. Dieses Dokument beschreibt **exakt** was die SaaS implementieren muss.

**Plugin:** Hubbee v2.0.0
**Namespace:** `Hubbee`
**REST-Namespace:** `bz/v1`
**Default SaaS URL:** `https://app.hubbee.io`

---

## 1. Enrollment-Flow (Site verbinden)

### Ablauf
```
┌─────────────────┐                    ┌─────────────────┐
│   Hubbee SaaS   │                    │  WordPress +    │
│   Dashboard     │                    │  Hubbee Plugin  │
└────────┬────────┘                    └────────┬────────┘
         │                                      │
         │  1. Admin generiert Onboarding-Token │
         │◄─────────────────────────────────────│
         │                                      │
         │  2. Admin kopiert Token ins Plugin   │
         │─────────────────────────────────────►│
         │                                      │
         │  3. Plugin ruft POST /api/v1/sites/enroll
         │◄─────────────────────────────────────│
         │     Header: Bearer {onboarding_token}│
         │     Body: { site_info }              │
         │                                      │
         │  4. SaaS gibt site_id + api_secret   │
         │─────────────────────────────────────►│
         │                                      │
         │  5. Plugin speichert Credentials     │
         │                                      │
         │  6. Ab jetzt: Push mit HMAC-Signatur │
         │─────────────────────────────────────►│
```

### SaaS muss implementieren: POST /api/v1/sites/enroll

**Request:**
```
POST https://app.hubbee.io/api/v1/sites/enroll
Authorization: Bearer {onboarding_token}
Content-Type: application/json
```

**Request Body (vom Plugin gesendet):**
```json
{
  "name": "Website Name",
  "url": "https://example.com",
  "rest_url": "https://example.com/wp-json/bz/v1/",
  "admin_email": "admin@example.com",
  "wp_version": "6.4.2",
  "php_version": "8.1.0",
  "plugin_version": "2.0.0",
  "locale": "de_DE",
  "timezone": "Europe/Berlin",
  "local_site_id": "uuid"
}
```

**Response (Erfolg - 200/201):**
```json
{
  "site_id": "saas-generated-uuid",
  "api_secret": "random-64-char-secret-for-hmac",
  "site_name": "Website Name"
}
```

**Fehler-Responses:**
| Status | Bedeutung |
|--------|-----------|
| 401 | Ungültiger/abgelaufener Onboarding-Token |
| 403 | Kein Zugriff (Subscription-Problem) |
| 409 | Site bereits registriert |
| 422 | Ungültige Site-Daten |
| 429 | Rate-Limit erreicht |

---

## 2. HMAC-Signatur (Authentifizierung)

Die SaaS muss alle Requests an das Plugin mit HMAC-SHA256 signieren.

### Signatur-Algorithmus

```typescript
function createSignature(body: string, timestamp: number, apiSecret: string): string {
  const payload = `${timestamp}.${body}`;
  return crypto
    .createHmac('sha256', apiSecret)
    .update(payload)
    .digest('hex');
}
```

### Required Headers für Push

```
X-Hubbee-Signature: {hmac-sha256-hex}
X-Hubbee-Timestamp: {unix-timestamp}
X-Hubbee-Site-Id: {site-id}
Content-Type: application/json
```

### Timestamp-Validierung
- Plugin akzeptiert nur Requests mit Timestamp ± 5 Minuten (300 Sekunden)
- Verhindert Replay-Attacken

---

## 3. Push-Endpoint aufrufen

### Endpoint
```
POST https://{wordpress-site}/wp-json/bz/v1/push
```

### Request Headers
```
X-Hubbee-Signature: {hmac-sha256-hex}
X-Hubbee-Timestamp: {unix-timestamp}
X-Hubbee-Site-Id: {site-id}
Content-Type: application/json
```

### Request Body
```json
{
  "tokens": [
    {
      "key": "string",           // REQUIRED
      "label": "string",         // Optional
      "description": "string",   // Optional
      "field_type": "text",      // "text" | "textarea" | "richtext"
      "value": "string",         // Der Token-Wert
      "locale": "",              // Sprach-Code oder leer
      "version": 1,              // REQUIRED, >= 1
      "section": "general"       // Gruppierung
    }
  ],
  "request_id": "uuid"           // Optional, für Idempotenz
}
```

### Response (Erfolg - 200)
```json
{
  "success": true,
  "processed": 5,
  "skipped": 2,
  "errors": [],
  "request_id": "uuid"
}
```

### Fehler-Codes
| Code | Status | Bedeutung |
|------|--------|-----------|
| `bz_not_connected` | 401 | Site nicht mit SaaS verbunden |
| `bz_missing_signature` | 401 | Kein Signatur-Header |
| `bz_missing_timestamp` | 401 | Kein Timestamp-Header |
| `bz_stale_request` | 401 | Timestamp zu alt (>5 min) |
| `bz_site_id_mismatch` | 401 | Site-ID stimmt nicht |
| `bz_invalid_signature` | 401 | Falsche HMAC-Signatur |
| `bz_invalid_payload` | 400 | Ungültige Payload-Struktur |

---

## 4. Versions-Logik (KRITISCH)

Das Plugin akzeptiert nur Tokens mit **höherer Version**.

```
Eingehend: 5, Gespeichert: 3 → AKZEPTIERT (5 > 3)
Eingehend: 3, Gespeichert: 5 → ÜBERSPRUNGEN (3 ≤ 5)
Eingehend: 5, Gespeichert: 5 → ÜBERSPRUNGEN (5 ≤ 5)
```

**SaaS muss:** Bei jeder Wertänderung die Version inkrementieren!

---

## 5. Idempotenz

Das Plugin trackt `request_id` um doppelte Pushes zu verhindern.

- Wenn `request_id` bereits verarbeitet: Response mit `processed: 0`
- SaaS sollte für jeden Push-Versuch die gleiche `request_id` verwenden

---

## 6. Weitere Plugin-Endpoints

### GET /wp-json/bz/v1/status (Öffentlich)
```json
{
  "site_id": "uuid",
  "plugin_version": "2.0.0",
  "mode": "agent",
  "rest_url": "https://example.com/wp-json/bz/v1/",
  "capabilities": ["push", "test-connection", "versioning"]
}
```

### POST /wp-json/bz/v1/test-connection (Mit Signatur)
Leerer Body, validiert nur Auth.

---

## 7. SaaS-zu-Plugin Ping (Optional)

### Endpoint (SaaS implementiert)
```
GET https://app.hubbee.io/api/v1/sites/{site_id}/ping
```

Mit Signatur-Headern vom Plugin aufgerufen.

---

## 8. Minimale SaaS-Funktionen

### Must-Have

| Funktion | Beschreibung |
|----------|--------------|
| **Onboarding-Token generieren** | Temporärer Token für Site-Enrollment |
| **POST /api/v1/sites/enroll** | Site registrieren, api_secret zurückgeben |
| **Sites speichern** | site_id, url, api_secret, status |
| **Token-Definitionen** | key, label, field_type, section |
| **Token-Werte** | value, locale, version (auto-increment!) |
| **HMAC-Signatur erstellen** | Für jeden Push-Request |
| **Push ausführen** | POST an Plugin mit Signatur |

### Datenmodell (Minimum)

```
Sites
├── id (UUID)
├── workspace_id
├── name
├── url
├── rest_url
├── api_secret (für HMAC)
├── status: "active" | "inactive" | "error"
├── last_push_at
├── enrolled_at
└── metadata (wp_version, php_version, etc.)

TokenDefinitions
├── id
├── workspace_id
├── token_key
├── label
├── description
├── field_type: "text" | "textarea" | "richtext"
├── section
└── sort_order

TokenValues
├── id
├── definition_id
├── locale
├── value
├── version (AUTO-INCREMENT bei Änderung!)
└── updated_at
```

---

## 9. Push-Implementierung (TypeScript)

```typescript
import crypto from 'crypto';

interface Site {
  id: string;
  url: string;
  rest_url: string;
  api_secret: string;
}

interface Token {
  key: string;
  label: string;
  description: string;
  field_type: 'text' | 'textarea' | 'richtext';
  value: string;
  locale: string;
  version: number;
  section: string;
}

function createSignature(body: string, timestamp: number, secret: string): string {
  const payload = `${timestamp}.${body}`;
  return crypto.createHmac('sha256', secret).update(payload).digest('hex');
}

async function pushToSite(site: Site, tokens: Token[]): Promise<PushResult> {
  const requestId = crypto.randomUUID();
  const timestamp = Math.floor(Date.now() / 1000);

  const payload = {
    tokens: tokens.map(t => ({
      key: t.key,
      label: t.label,
      description: t.description,
      field_type: t.field_type,
      value: t.value,
      locale: t.locale || '',
      version: t.version,
      section: t.section || 'general'
    })),
    request_id: requestId
  };

  const body = JSON.stringify(payload);
  const signature = createSignature(body, timestamp, site.api_secret);

  const response = await fetch(`${site.rest_url}push`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Hubbee-Signature': signature,
      'X-Hubbee-Timestamp': String(timestamp),
      'X-Hubbee-Site-Id': site.id
    },
    body
  });

  return response.json();
}
```

---

## 10. Enrollment-Implementierung (TypeScript)

```typescript
interface EnrollRequest {
  name: string;
  url: string;
  rest_url: string;
  admin_email: string;
  wp_version: string;
  php_version: string;
  plugin_version: string;
  locale: string;
  timezone: string;
  local_site_id: string;
}

async function enrollSite(
  onboardingToken: string,
  siteInfo: EnrollRequest
): Promise<{ site_id: string; api_secret: string }> {

  const response = await fetch('https://app.hubbee.io/api/v1/sites/enroll', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${onboardingToken}`,
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify(siteInfo)
  });

  if (!response.ok) {
    throw new Error(`Enrollment failed: ${response.status}`);
  }

  return response.json();
}
```

---

## 11. Checkliste für SaaS

- [ ] Onboarding-Tokens generieren (temporär, z.B. 1h gültig)
- [ ] POST /api/v1/sites/enroll implementieren
- [ ] api_secret generieren (64+ Zeichen random)
- [ ] Sites mit api_secret speichern
- [ ] Token-Definitionen CRUD
- [ ] Token-Werte mit **auto-increment Version**
- [ ] HMAC-SHA256 Signatur-Funktion
- [ ] Push mit korrekten Headers senden
- [ ] Idempotenz-Support (request_id)
- [ ] Site-Status tracken (active/error)

---

## Zusammenfassung

Die SaaS muss:

1. **Enrollment:** `POST /api/v1/sites/enroll` mit Bearer-Token
2. **Credentials:** `site_id` + `api_secret` zurückgeben
3. **Push:** `POST /wp-json/bz/v1/push` mit HMAC-Signatur
4. **Versionierung:** Version bei jeder Änderung inkrementieren

Das Plugin erwartet **HMAC-SHA256** Authentifizierung, keine API-Keys mehr!

---

## Plugin-Dateien Referenz

```
/wp-content/plugins/hubbee/
├── hubbee.php                          # Main plugin file (v2.0.0)
├── includes/
│   ├── Core/
│   │   └── Plugin.php                  # Bootstrap
│   ├── SaaS/
│   │   ├── ConnectionManager.php       # Credentials + Encryption
│   │   ├── EnrollmentService.php       # Enrollment-Flow
│   │   └── SignatureValidator.php      # HMAC Validation
│   ├── REST/
│   │   ├── RestController.php          # Namespace: bz/v1
│   │   ├── PushEndpoint.php            # POST /push
│   │   ├── StatusEndpoint.php          # GET /status
│   │   ├── EnrollEndpoint.php          # POST /enroll, /disconnect
│   │   └── TestConnectionEndpoint.php
│   ├── Agent/
│   │   ├── AgentManager.php
│   │   ├── TokenService.php            # Token-Abruf + Caching
│   │   └── PushHandler.php             # Token-Verarbeitung
│   ├── Storage/
│   │   ├── Database.php                # Schema
│   │   └── TokenRepository.php         # CRUD
│   ├── Security/
│   │   ├── Authenticator.php
│   │   └── Sanitizer.php
│   └── Elementor/
│       ├── ElementorManager.php
│       └── TextTokenTag.php            # Dynamic Tag: bz-text-token
```

---

## Version-Skew & Backward-Compatibility Contract

The plugin does **not** self-update its own binary. Compatibility is coordinated
via a version signal the SaaS MAY include in the `/site-commands` response:

- `min_plugin_version` (string, semver): the lowest plugin version the SaaS still
  fully supports. When the connected site runs an older version, the plugin
  stores `bz_min_plugin_version` and shows a dismiss-free admin notice prompting
  an update (`AdminController::display_notices`). The field is optional and
  forward-compatible — omitting it is a no-op.

**SaaS policy (recommended):** support the current plugin version plus the last
two minor versions. Only raise `min_plugin_version` after a deprecation window so
sites have time to update. The current plugin requires **PHP 8.1+**.

