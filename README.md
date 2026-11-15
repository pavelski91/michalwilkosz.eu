# michalwilkosz.eu

Statyczna strona (HTML/CSS/JS) + dwa endpointy PHP w `api/`.

- `api/newsletter.php` — zapis do grupy MailerLite
- `api/send-mail.php` — formularz kontaktowy (PHPMailer/SMTP)
- `api/PHPMailer/` — PHPMailer 6.9.3 (LGPL 2.1, licencja w katalogu)

Sekrety (`mailerlite.php`, `smtp.php`) leżą poza `www`, w `/home/michall/config/` — **nigdy w repo**.
