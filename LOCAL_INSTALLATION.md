# Lokale Installation des Logto Laravel SDK

Diese Anleitung erklärt, wie du das **TIVENTS Logto Laravel SDK** in einem **lokalen Laravel-Projekt** einbindest und nutzt, ohne es über Packagist zu veröffentlichen.

---

## 📥 Voraussetzungen

- ✅ PHP 8.5 oder höher
- ✅ Laravel 13.x
- ✅ Composer 2.x
- ✅ Ein laufendes Laravel-Projekt
- ✅ Zugriff auf das lokale `logto-laravel-sdk`-Verzeichnis

---

## 🔗 Methode 1: Lokale Entwicklung mit `path`-Repository (Empfohlen)

Diese Methode ist ideal für die Entwicklung, wenn sich beide Projekte auf demselben System befinden.

### 1. Pfad zum Package im Hauptprojekt hinzufügen

In deinem **Laravel-Hauptprojekt** (nicht im Package-Verzeichnis) führe folgenden Befehl aus:

```bash
# Navigiere in dein Laravel-Projekt
cd /pfad/zu/deinem/laravel-projekt

# Füge das lokale Package als path-Repository hinzu
composer config repositories.logto-laravel-sdk vcs /absoluter/pfad/zu/logto-laravel-sdk
```

**Beispiel:**
```bash
composer config repositories.logto-laravel-sdk vcs /Users/benutzer/projekte/logto-laravel-sdk
```

### 2. Package als Abhängigkeit hinzufügen

Füge das Package zu deiner `composer.json` hinzu:

```bash
composer require tivents/logto-laravel-sdk:dev-main
```

> **Hinweis:** `dev-main` bezieht sich auf den aktuellen Entwicklungszweig. Falls du einen spezifischen Branch nutzen möchtest, ersetze `dev-main` mit dem Branch-Namen (z.B. `dev-develop`).

### 3. Composer-Update ausführen

```bash
composer update tivents/logto-laravel-sdk
```

Composer lädt jetzt das Package direkt aus dem lokalen Verzeichnis.

---

## 🔗 Methode 2: Symbolic Link (Alternative für schnelle Tests)

Falls du schnell testen möchtest, ohne Composer zu konfigurieren:

### 1. Symbolischen Link erstellen

```bash
# In deinem Laravel-Projekt
cd /pfad/zu/deinem/laravel-projekt

# Verzeichnis vendor/tivents erstellen
mkdir -p vendor/tivents

# Symbolischen Link zum Package erstellen
ln -s /absoluter/pfad/zu/logto-laravel-sdk vendor/tivents/logto-laravel-sdk
```

### 2. Autoload neu generieren

```bash
composer dump-autoload
```

### 3. Service Provider manuell registrieren

Falls das automatische Laden nicht funktioniert, füge den Service Provider manuell in `config/app.php` hinzu:

```php
// config/app.php
'providers' => [
    // ... andere Provider
    TIVENTS\LogtoLaravelSdk\LogtoServiceProvider::class,
],

'aliases' => [
    // ... andere Aliases
    'Logto' => TIVENTS\LogtoLaravelSdk\Facades\Logto::class,
],
```

---

## 🔗 Methode 3: Git Submodule (Für Versionierung)

Falls du das Package als Submodule verwalten möchtest:

### 1. Submodule hinzufügen

```bash
cd /pfad/zu/deinem/laravel-projekt
git submodule add /pfad/zu/logto-laravel-sdk vendor/tivents/logto-laravel-sdk
```

### 2. Composer konfigueren

Füge das Repository zur `composer.json` hinzu:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "./vendor/tivents/logto-laravel-sdk"
        }
    ],
    "require": {
        "tivents/logto-laravel-sdk": "dev-main"
    }
}
```

### 3. Installieren

```bash
composer install
```

---

## ⚙️ Konfiguration

Nach der Installation musst du die Konfiguration veröffentlichen und anpassen.

### 1. Konfiguration veröffentlichen

```bash
php artisan vendor:publish --tag=logto-config
```

Dies erstellt die Datei `config/logto.php` in deinem Laravel-Projekt.

### 2. Migration veröffentlichen und ausführen

```bash
# Migration veröffentlichen
php artisan vendor:publish --tag=logto-migrations

# Migration ausführen
php artisan migrate
```

### 3. Umgebungsvariablen anpassen

Füge folgende Variablen zu deiner `.env`-Datei hinzu:

```env
# Logto Configuration
LOGTO_APP_ID=your-logto-app-id
LOGTO_APP_SECRET=your-logto-app-secret
LOGTO_ENDPOINT=https://deine-instanz.logto.app

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
```

### 4. Auth-Guard registrieren (optional)

Der Guard wird automatisch registriert. Falls du einen anderen Guard-Namen verwenden möchtest, passe die Konfiguration an:

```php
// config/auth.php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    
    'logto' => [
        'driver' => 'logto',
        'provider' => 'users',
    ],
],
```

---

## 📋 Benutzer-Modell anpassen

Das Package erstellt automatisch Benutzer, wenn `LOGTO_AUTO_CREATE_USERS=true` gesetzt ist. Dafür muss dein User-Modell folgende Felder haben:

```php
// app/Models/User.php

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'logto_id',        // Wird vom Package gesetzt
        'avatar',           // Optional für Profilbilder
        'phone',            // Optional für Telefonnummern
        'phone_verified_at', // Optional
        'email_verified_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
    ];
}
```

Führe dann eine Migration aus, um die fehlenden Spalten hinzuzufügen:

```bash
php artisan make:migration add_logto_columns_to_users_table --table=users
```

Und füge in der Migration die fehlenden Spalten hinzu:

```php
// In der Migration
public function up()
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('logto_id')->nullable()->unique()->after('id');
        $table->string('avatar')->nullable()->after('email');
        $table->string('phone')->nullable()->after('avatar');
        $table->timestamp('phone_verified_at')->nullable()->after('phone');
    });
}
```

---

## 🧪 Testen der Installation

### 1. Route zum Testen erstellen

Erstelle eine Test-Route in `routes/web.php`:

```php
Route::get('/test-logto', function () {
    return [
        'logto_configured' => config('logto.app_id') !== null,
        'guard_registered' => Auth::hasGuard('logto'),
        'routes_registered' => [
            'login' => route('logto.login'),
            'callback' => route('logto.callback'),
            'logout' => route('logto.logout'),
        ],
    ];
});
```

Besuche dann `http://deine-domain.test/test-logto` und überprüfe, dass alles `true` ist.

### 2. Login-Link testen

Erstelle eine View mit einem Login-Link:

```php
<!-- resources/views/welcome.blade.php -->
<a href="@logto">Login mit Logto</a>

<!-- Oder -->
<a href="{{ route('logto.login') }}">Login mit Logto</a>
```

### 3. Geschützte Route testen

```php
Route::get('/dashboard', function () {
    return 'Du bist angemeldet!';
})->middleware(['auth:logto']);
```

---

## 🔧 Logto Application einrichten

1. **Gehe zu deinem Logto Dashboard** (z.B. https://cloud.logto.io)
2. **Erstelle eine neue Application**
3. **Wähle den Application-Typ:**
   - Für traditionelle Web-Apps: **Traditional Web Application**
   - Für SPAs: **Single Page Application**

4. **Konfiguriere die Redirect URIs:**
   ```
   http://localhost/auth/logto/callback
   https://deine-domain.de/auth/logto/callback
   ```

5. **Konfiguriere die Post-Logout Redirect URIs:**
   ```
   http://localhost/
   https://deine-domain.de/
   ```

6. **Aktiviere die benötigten Authentifizierungsmethoden:**
   - Email/Password
   - Social Login (Google, GitHub, etc.)
   - MFA (optional)

7. **Kopiere Application ID und Secret** in deine `.env`:
   ```env
   LOGTO_APP_ID=deine-app-id
   LOGTO_APP_SECRET=dein-app-secret
   ```

---

## 🔄 Ändern und Testen des Packages

Wenn du Änderungen am Package vornimmst und diese im Hauptprojekt testen möchtest:

### 1. Änderungen im Package durchführen

Arbeite direkt im `logto-laravel-sdk`-Verzeichnis.

### 2. Composer-Update im Hauptprojekt ausführen

```bash
cd /pfad/zu/deinem/laravel-projekt
composer update tivents/logto-laravel-sdk
```

> **Tipp:** Falls du oft Änderungen testest, kannst du auch einfach `composer dump-autoload` im Hauptprojekt ausführen, wenn du nur PHP-Dateien änderst.

### 3. Tests ausführen

Im Package-Verzeichnis:
```bash
# Installiere Entwicklungsabhängigkeiten
composer install

# Führe Pest-Tests aus
./vendor/bin/pest
```

---

## 📦 Optional: Package auf Packagist veröffentlichen

Falls du das Package später öffentlich veröffentlichen möchtest:

### 1. Package auf GitHub hochladen

```bash
cd /pfad/zu/logto-laravel-sdk
git init
git add .
git commit -m "Initial commit"
git remote add origin git@github.com:dein-benutzername/logto-laravel-sdk.git
git push -u origin main
```

### 2. Package auf Packagist registrieren

1. Gehe zu [https://packagist.org](https://packagist.org)
2. Melde dich an
3. Klicke auf **Submit**
4. Gib die GitHub-Repository-URL ein: `https://github.com/dein-benutzername/logto-laravel-sdk`
5. Packagist wird das Package automatisch analysieren und veröffentlichen

### 3. Package installieren

Jetzt können andere das Package einfach installieren mit:
```bash
composer require tivents/logto-laravel-sdk
```

---

## 🛠️ Fehlersuche

### Häufige Probleme und Lösungen

#### Problem: "Class not found"
- **Lösung:** Führe `composer dump-autoload` aus
- **Lösung:** Überprüfe die Namespace-Importe in deinen Dateien

#### Problem: "Service Provider not found"
- **Lösung:** Stelle sicher, dass der Service Provider in `config/app.php` registriert ist
- **Lösung:** Überprüfe die `composer.json` des Packages (Extra -> Laravel -> Providers)

#### Problem: "Route not found"
- **Lösung:** Überprüfe, ob die Routen registriert sind (`php artisan route:list`)
- **Lösung:** Stelle sicher, dass das Package korrekt geladen wird

#### Problem: "Invalid state parameter"
- **Lösung:** Überprüfe die Session-Konfiguration (`APP_SESSION_DRIVER`)
- **Lösung:** Stelle sicher, dass die Domain in `config/session.php` korrekt ist

#### Problem: "No user ID in user info"
- **Lösung:** Überprüfe, ob der `sub`-Claim in der UserInfo-Response enthalten ist
- **Lösung:** Stelle sicher, dass der `openid`-Scope angefordert wird

### Debugging

Aktiviere das Logging für detaillierte Fehlermeldungen:

```env
LOGTO_LOGGING_ENABLED=true
LOGTO_LOGGING_LEVEL=debug
```

Überprüfe dann die Logs in `storage/logs/laravel.log`.

---

## 📖 Nächste Schritte

1. ✅ **Installation abgeschlossen** - Das Package ist jetzt in deinem Projekt eingebunden
2. 🔧 **Konfiguration anpassen** - Passe die Einstellungen in `.env` und `config/logto.php` an
3. 🔗 **Logto Application einrichten** - Erstelle eine Application in deinem Logto Dashboard
4. 🧪 **Testen** - Teste die Authentifizierung mit einer Test-Application
5. 🚀 **Produktion** - Setze `APP_ENV=production` und teste in der echten Umgebung

---

## 📞 Unterstützung

Falls du Fragen oder Probleme hast:

1. **Dokumentation** - Lies die [README.md](README.md) für detaillierte Informationen
2. **Fehler melden** - Erstelle ein Issue im GitHub-Repository
3. **Community** - Frage in Laravel- oder Logto-Communitys nach

Viel Erfolg mit dem TIVENTS Logto Laravel SDK! 🎉
