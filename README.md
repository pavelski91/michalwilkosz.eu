# michalwilkosz.eu

Statyczna strona (HTML/CSS/JS) + dwa endpointy PHP w `api/`.

- `api/newsletter.php` — zapis do grupy MailerLite
- `api/send-mail.php` — formularz kontaktowy (PHPMailer/SMTP)
- `api/PHPMailer/` — PHPMailer 6.9.3 (LGPL 2.1, licencja w katalogu)

Sekrety (`mailerlite.php`, `smtp.php`) leżą poza `www`, w `/home/michall/config/` — **nigdy w repo**.

## Wdrożenie (auto-deploy)

Po scaleniu pull requesta do `main` workflow `.github/workflows/deploy.yml` wysyła zmienione
pliki przez SFTP na hosting OVH (katalog `www`). Nic nie jest usuwane na serwerze.

Na serwer trafia **tylko biała lista**: `index.html`, `cookies.html`, `polityka-prywatnosci.html`,
`robots.txt`, `sitemap.xml`, `favicon.ico`, `apple-touch-icon.png` oraz katalogi `css/`, `js/`,
`assets/`, `images/`, `api/`. Wszystko inne w repo (dokumenty robocze, źródła, notatki, narzędzia)
zostaje wyłącznie na GitHubie i nie jest publikowane.

Wymagane sekrety (Settings → Secrets and variables → Actions → New repository secret),
wartości z OVH: Hosting → FTP – SSH:

| Sekret | Opis |
|---|---|
| `FTP_SERVER` | host SFTP, np. `ftp.clusterXXX.hosting.ovh.net` |
| `FTP_USERNAME` | login FTP |
| `FTP_PASSWORD` | hasło FTP |
| `FTP_SERVER_DIR` | opcjonalnie, domyślnie `www` |

Bez sekretów workflow się nie wywala — pomija deploy i zostawia ostrzeżenie.
Ręczne uruchomienie: Actions → Deploy na michalwilkosz.eu → Run workflow.
