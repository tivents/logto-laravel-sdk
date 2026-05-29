# Logto Laravel SDK

Ein Laravel-Package zur Integration von Logto Authentication. Unterstützt OIDC (OpenID Connect) und OAuth 2.0 Flows.

## Features

- ✅ **OIDC Authentication** - OpenID Connect für Login/Registration
- ✅ **OAuth 2.0** - Token-basierte API-Authentifizierung
- ✅ **PKCE Support** - Proof Key for Code Exchange für mehr Sicherheit
- ✅ **Token Management** - Automatische Token-Speicherung und Refresh
- ✅ **User Synchronization** - Automatische Benutzererstellung und -aktualisierung
- ✅ **Guard Integration** - Vollständige Laravel Auth-Guard Integration
- ✅ **Middleware** - Schutz von Routen mit `auth:logto`
- ✅ **Blade Directives** - Einfache Integration in Blade-Templates
- ✅ **Multi-Factor Authentication** - Unterstützung für Logto MFA
- ✅ **Social Login** - Integration sozialer Anbieter über Logto
- ✅ **Official Logto SDK Integration** - Direkte Nutzung des `logto/sdk` für maximale Kompatibilität
- ✅ **Automatic Fallback** - Fallback auf manuelle Implementierung falls SDK nicht verfügbar

## Voraussetzungen

- PHP 8.5 oder höher
- Laravel 13.x
- Composer
- Eine Logto-Instanz (z.B. https://cloud.logto.io oder selbst gehostet)

## Installation

### 1. Package installieren

```bash
composer require tivents/logto-laravel-sdk
```

### 2. Service Provider registrieren (optional für Laravel < 10)

In `config/app.php` den Service Provider hinzufügen (ab Laravel 10 wird das automatisch gemacht):

```php
'providers' => [
    // ... andere Provider
    TIVENTS\LogtoLaravelSdk\LogtoServiceProvider::class,
],

'aliases' => [
    // ... andere Aliases
    'Logto' => TIVENTS\LogtoLaravelSdk\Facades\Logto::class,
],
```

### 3. Konfiguration veröffentlichen

```bash
php artisan vendor:publish --tag=logto-config
```

### 4. SDK Integration aktivieren (optional)

Das Package nutzt automatisch das offizielle `logto/sdk` für alle Authentifizierungs-Flows. 
Die Integration ist bereits aktiviert und erfordert keine zusätzliche Konfiguration.

Falls du das SDK explizit deaktivieren möchtest, kannst du den SDK Adapter in der Service Provider Registrierung entfernen.

### 4. Migration ausführen

```bash
php artisan vendor:publish --tag=logto-migrations
php artisan migrate
```

### 5. Umgebungsvariablen konfigurieren

Füge folgende Variablen zu deiner `.env`-Datei hinzu:

```env
# Logto Configuration
LOGTO_APP_ID=your-app-id
LOGTO_APP_SECRET=your-app-secret
LOGTO_ENDPOINT=https://your-instance.logto.app

# OIDC Configuration
LOGTO_OIDC_ENABLED=true
LOGTO_OIDC_REDIRECT_URI=/auth/logto/callback
LOGTO_OIDC_POST_LOGOUT_REDIRECT_URI=/
LOGTO_OIDC_SCOPES=openid profile email
LOGTO_OIDC_PKCE=true

# User Configuration
LOGTO_USER_MODEL=App\Models\User
LOGTO_AUTO_CREATE_USERS=true
LOGTO_AUTO_UPDATE_USERS=true
LOGTO_DEFAULT_ROLE=user

# Token Configuration
LOGTO_ACCESS_TOKEN_LIFETIME=3600
LOGTO_REFRESH_TOKEN_LIFETIME=86400
LOGTO_STORE_ENCRYPTED=true

# Guard Configuration
LOGTO_GUARD_NAME=logto
LOGTO_GUARD_DRIVER=session
LOGTO_GUARD_PROVIDER=users
```

## Logto Application Einrichtung

1. Gehe zu deinem Logto Dashboard
2. Erstelle eine neue Application
3. Wähle **Single Page Application** oder **Traditional Web Application**
4. Konfiguriere die Redirect URIs:
   - `http://localhost/auth/callback` (für Entwicklung)
   - `https://deine-domain.de/auth/callback` (für Produktion)
5. Konfiguriere die Post-Logout Redirect URIs:
   - `http://localhost/` (für Entwicklung)
   - `https://deine-domain.de/` (für Produktion)
6. Aktiviere die benötigten Authentifizierungsmethoden (Email/Password, Social, etc.)
7. Speichere die Application ID und das Secret in deiner `.env`-Datei

## Verwendung

### Grundlegende Authentifizierung

#### Login-Link in Blade

```php
<!-- Einfacher Login-Link -->
<a href="@logto">Login mit Logto</a>

<!-- Oder manuell -->
<a href="{{ route('logto.login') }}">Login mit Logto</a>

#### Direkte SDK Methoden nutzen

Das Package stellt auch direkte Zugriffsmethoden auf das offizielle Logto SDK bereit:

```php
use TIVENTS\LogtoLaravelSdk\Facades\Logto;

// Login-URL mit dem offiziellen SDK generieren
$loginUrl = Logto::getSdkSignInUrl('https://deine-domain.de/auth/logto/callback');

// UserInfo mit dem offiziellen SDK abrufen
$userInfo = Logto::getSdkUserInfo();

// SDK-Session bereinigen
Logto::clearSdkSession();

// Prüfen ob der Benutzer über das SDK authentifiziert ist
$isAuthenticated = Logto::isSdkAuthenticated();

// SDK Access Token abrufen
$accessToken = Logto::getSdkAccessToken();

// SDK ID Token abrufen
$idToken = Logto::getSdkIdToken();
```

**Vorteile der SDK-Methoden:**
- ✅ **Offizielle Implementierung** - Nutzt die getesteten SDK-Funktionen
- ✅ **PKCE automatisch** - Proof Key for Code Exchange wird vom SDK gehandhabt
- ✅ **State Management** - CSRF-Schutz wird automatisch verwaltet
- ✅ **Token Refresh** - Access Token Refresh wird automatisch durchgeführt
- ✅ **Automatische Fallbacks** - Falls das SDK nicht verfügbar ist, wird auf die manuelle Implementierung zurückgegriffen

<!-- Oder mit spezieller Redirect-URI -->
<a href="{{ route('logto.login') }}?redirect_uri={{ url('/dashboard') }}">Login mit Logto</a>
```

#### Schutz von Routen

```php
// In web.php
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth:logto']);

// Oder mit Gruppe
Route::middleware(['auth:logto'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'index']);
});
```

#### Manuelle Authentifizierung

```php
use TIVENTS\LogtoLaravelSdk\Facades\Logto;

// Autorisierungs-URL generieren
$authUrl = Logto::getAuthorizationUrl();

// Nach dem Callback: Code gegen Tokens tauschen
$tokens = Logto::exchangeCodeForTokens($code);

// Benutzerinformationen abrufen
$userInfo = Logto::getUserInfo($tokens['access_token']);
```

### Auth Guard verwenden

```php
use Illuminate\Support\Facades\Auth;

// Prüfen ob Benutzer authentifiziert ist
if (Auth::guard('logto')->check()) {
    // Benutzer ist authentifiziert
    $user = Auth::guard('logto')->user();
}

// Benutzer abmelden
Auth::guard('logto')->logout();

// Access Token abrufen
$accessToken = Auth::guard('logto')->getAccessToken();

// Token refreshen
$newTokens = Auth::guard('logto')->refreshToken();

// Benutzerinformationen abrufen
$userInfo = Auth::guard('logto')->getUserInfo();
```

### API-Anfragen

```php
use TIVENTS\LogtoLaravelSdk\Facades\Logto;

// Authentifizierte API-Anfrage
$response = Logto::request('GET', '/api/user', [], $userId);

// Oder mit spezifischen Optionen
$response = Logto::request('POST', '/api/data', [
    'json' => ['key' => 'value'],
], $userId);
```

### Client Credentials Flow (Machine-to-Machine)

```php
use TIVENTS\LogtoLaravelSdk\Facades\Logto;

// Token für M2M abrufen
$tokens = Logto::clientCredentialsFlow(['api:read', 'api:write']);

// Mit Token API-Anfragen stellen
$response = Logto::request('GET', '/api/protected', [
    'headers' => [
        'Authorization' => 'Bearer ' . $tokens['access_token'],
    ],
]);
```

### Blade Directives

```blade
<!-- Authentifizierungs-URL -->
@logto

<!-- Aktueller Benutzer -->
@logtoUser

<!-- Logout-URL -->
@logtoLogout
```

### E-Mail-Verifizierung

```php
// Route mit E-Mail-Verifizierung schützen
Route::get('/profile', function () {
    return view('profile');
})->middleware(['auth:logto', 'verified.logto']);
```

## Konfiguration

### Benutzer-Mapping

In der `config/logto.php` kannst du das Mapping zwischen Logto-Claims und Laravel-Benutzerattributen konfigurieren:

```php
'user' => [
    'mapping' => [
        'id' => 'sub',           // Logto 'sub' → Laravel 'id'
        'name' => 'name',        // Logto 'name' → Laravel 'name'
        'email' => 'email',      // Logto 'email' → Laravel 'email'
        'email_verified' => 'email_verified',
        'picture' => 'picture',   // Logto 'picture' → Laravel 'avatar'
        'phone' => 'phone',
        'phone_verified' => 'phone_verified',
    ],
],
```

### Scopes

In der `config/logto.php` kannst du die OIDC-Scopes konfigurieren:

```php
'oidc' => [
    'scopes' => explode(' ', env('LOGTO_OIDC_SCOPES', 'openid profile email')),
],
```

### Token-Konfiguration

```php
'tokens' => [
    'access_token_lifetime' => env('LOGTO_ACCESS_TOKEN_LIFETIME', 3600),
    'refresh_token_lifetime' => env('LOGTO_REFRESH_TOKEN_LIFETIME', 86400),
    'store_encrypted' => env('LOGTO_STORE_ENCRYPTED', true),
],
```

## Fehlersuche

### Häufige Probleme

#### "Invalid state parameter"
- Stelle sicher, dass die Session korrekt funktioniert
- Prüfe, dass `APP_SESSION_DRIVER` korrekt konfiguriert ist
- Stelle sicher, dass die Domain in der Session-Konfiguration korrekt ist

#### "No user ID in user info"
- Prüfe, dass der `sub`-Claim in der UserInfo-Response enthalten ist
- Stelle sicher, dass die korrekten Scopes (`openid`) angefordert werden

#### "Failed to discover OIDC configuration"
- Prüfe, dass `LOGTO_ENDPOINT` korrekt ist
- Stelle sicher, dass die Logto-Instanz erreichbar ist
- Prüfe, dass die OIDC-Discovery-URL korrekt ist

### Logging aktivieren

```env
LOGTO_LOGGING_ENABLED=true
LOGTO_LOGGING_LEVEL=debug
```

## Sicherheit

### Wichtige Sicherheitshinweise

1. **HTTPS**: Verwende immer HTTPS in der Produktion
2. **State-Parameter**: Der State-Parameter wird für CSRF-Schutz verwendet - änder ihn nicht
3. **PKCE**: PKCE ist standardmäßig aktiviert und sollte nicht deaktiviert werden
4. **Token-Speicherung**: Tokens werden standardmäßig verschlüsselt gespeichert
5. **Session-Sicherheit**: Stelle sicher, dass deine Session-Konfiguration sicher ist

## API Referenz

### Logto Facade

#### Standardmethoden (mit Fallback auf manuelle Implementierung)
```php
Logto::getAuthorizationUrl(?string $state, ?string $nonce, ?string $redirectUri)
Logto::exchangeCodeForTokens(string $code)
Logto::refreshToken(string $refreshToken)
Logto::getUserInfo(string $accessToken)
Logto::validateIdToken(string $idToken, ?string $nonce)
Logto::logout(?string $idToken, ?string $postLogoutRedirectUri)
Logto::getAccessToken(string $userId)
Logto::request(string $method, string $path, array $options, ?string $userId)
```

#### Offizielle SDK Methoden (direkter Zugriff auf logto/sdk)
```php
// Authentifizierung
Logto::getSdkSignInUrl(string $redirectUri, array $extraParams = [])
Logto::handleSdkCallback(string $redirectUri)
Logto::getSdkSignOutUrl(?string $postLogoutRedirectUri, ?string $idTokenHint)

// Token Management
Logto::getSdkAccessToken(?string $resource = null)
Logto::getSdkIdToken()
Logto::getSdkRefreshToken()
Logto::refreshSdkAccessToken()

// Session Management
Logto::clearSdkSession()
Logto::isSdkAuthenticated()

// Storage (für erweiterte Nutzung)
Logto::getSdkAdapter()->get(StorageKey $key)
Logto::getSdkAdapter()->set(StorageKey $key, ?string $value)
Logto::getSdkAdapter()->delete(StorageKey $key)
```
Logto::getAccessToken(string $userId)
Logto::request(string $method, string $path, array $options = [], ?string $userId = null)
Logto::getUserByAccessToken(string $accessToken)
Logto::clientCredentialsFlow(array $scopes = [])
```

### TokenManager

```php
$tokenManager = app('TIVENTS\LogtoLaravelSdk\Services\TokenManager');

$tokenManager->storeTokens(string $userId, array $tokens)
$tokenManager->getAccessToken(string $userId)
$tokenManager->getRefreshToken(string $userId)
$tokenManager->refreshAccessToken(string $userId, LogtoClient $client)
$tokenManager->clearTokens(string $userId)
$tokenManager->hasValidTokens(string $userId)
$tokenManager->getTokenExpiration(string $userId)
$tokenManager->getTokenType(string $userId)
```

## Entwicklung

### Tests ausführen

```bash
# Alle Tests
composer test

# Nur Unit Tests
./vendor/bin/pest tests/Unit

# Nur Feature Tests
./vendor/bin/pest tests/Feature

# Nur SDK Adapter Tests
./vendor/bin/pest tests/Unit/LogtoSdkAdapterTest.php
```

### Package neu bauen

```bash
composer dump-autoload
```

## SDK Integration Details

### Architektur

Das Package nutzt einen **Adapter-Pattern** um das offizielle `logto/sdk` mit Laravel zu integrieren:

```
┌─────────────────────────────────────────────┐
│              Laravel Application               │
└─────────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────┐
│           LogtoServiceProvider                │
│  - Registriert LogtoSdkAdapter                 │
│  - Registriert LogtoClient                    │
│  - Registriert AuthController                 │
└─────────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────┐
│              LogtoSdkAdapter                   │
│  - Implementiert Storage Interface            │
│  - Erstellt LogtoConfig                       │
│  - Erstellt LogtoClient (SDK)                 │
│  - Übersetzt Laravel Session ↔ SDK Storage   │
└─────────────────────────────────────────────┘
                    │
        ┌───────────┴───────────┐
        ▼                       ▼
┌─────────────────┐   ┌─────────────────┐
│   LogtoClient    │   │    Logto SDK     │
│ (Laravel Wrapper)│   │ (Offiziell)     │
│                 │   │                 │
│ - getAuthorizationUrl()│   │ - signIn()     │
│ - exchangeCodeForTokens()│ │ - handleSignInCallback()│
│ - Fallback Logik │   │ - fetchUserInfo()│
└─────────────────┘   └─────────────────┘
```

### Fallback-Mechanismus

Alle Hauptmethoden des `LogtoClient` versuchen zuerst das offizielle SDK zu nutzen. 
Falls das SDK nicht verfügbar ist oder ein Fehler auftritt, wird automatisch auf 
die manuelle Implementierung zurückgegriffen. Das gewährleistet maximale Kompatibilität.

Beispiel:
```php
public function getAuthorizationUrl(...)
{
    // 1. Versuche SDK Adapter
    if ($this->sdkAdapter) {
        try {
            return $this->sdkAdapter->getSignInUrl(...);
        } catch (\Exception $e) {
            Log::warning('SDK failed, falling back...');
        }
    }
    
    // 2. Fallback auf manuelle Implementierung
    return $this->getAuthorizationUrlLegacy(...);
}
```

## Mitwirken

Pull Requests sind willkommen. Bitte stelle sicher, dass:

1. Tests vorhanden sind und passieren
2. Der Code den PSR-12-Standards entspricht
3. Die Dokumentation aktualisiert wird

## Lizenz

Dieses Package ist unter der [MIT-Lizenz](LICENSE) lizenziert.

## Credits

- [TIVENTS](https://tivents.de)
- [Logto](https://logto.io)
- Alle Mitwirkenden

---

**TIVENTS Logto Laravel SDK** - Einfache Integration von Logto Authentication in Laravel-Anwendungen.
