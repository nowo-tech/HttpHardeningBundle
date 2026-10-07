# Configuration

Root key: `nowo_http_hardening`.

## security_headers

| Key | Default | Notes |
|-----|---------|-------|
| `enabled` | `true` | Register the response subscriber |
| `x_content_type_options` | `nosniff` | Empty string skips the header |
| `x_frame_options` | `SAMEORIGIN` | |
| `referrer_policy` | `strict-origin-when-cross-origin` | |
| `permissions_policy` | `camera=(), microphone=(), geolocation=()` | |
| `cross_origin_resource_policy` | `same-origin` | |
| `hsts_enabled` | `true` | |
| `hsts` | `max-age=31536000; includeSubDomains` | Do not add `preload` until the checklist is complete |
| `hsts_skip_loopback` | `true` | Skip HSTS on localhost / 127.0.0.1 / ::1 / `*.localhost` |

Existing response headers are never overwritten.

## anonymous_session_strip

| Key | Default | Notes |
|-----|---------|-------|
| `enabled` | `false` | Requires `security.token_storage` |
| `session_cookie_name` | `PHPSESSID` | Match the host session cookie |
| `public_route_name_prefixes` | `[]` | e.g. `site_`, `legal_` |
| `public_route_names` | `[]` | Exact route names |
| `cache_control` | `public, max-age=120, must-revalidate` | Applied when the session cookie was stripped and `public` is absent |

Authenticated users (`UserInterface`) always keep the session cookie. Other cookies (consent, etc.) are preserved.
