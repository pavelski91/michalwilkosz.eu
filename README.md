# michalwilkosz.eu

Statyczna strona (HTML/CSS/JS) + dwa endpointy PHP w `api/`.

- `api/newsletter.php` — zapis do grupy MailerLite
- `api/send-mail.php` — formularz kontaktowy (PHPMailer/SMTP); **do dodania do repo** (lokalnie w `api/`)
- `api/PHPMailer/` — biblioteka, wgrywana na serwer osobno (ignorowana w git)

Sekrety (`mailerlite.php`, `smtp.php`) leżą poza `www`, w `/home/michall/config/` — **nigdy w repo**.
