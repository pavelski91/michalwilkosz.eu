<?php
/**
 * MICHAŁ WILKOSZ — michalwilkosz.eu
 * api/send-mail.php
 *
 * Backend endpoint dla formularza kontaktowego (#contactForm, js/main.js).
 * Przyjmuje dane z frontendu, waliduje je server-side, i wysyła wiadomość
 * e-mail przez uwierzytelnione SMTP (PHPMailer) na skrzynkę Michała.
 * Konfiguracja SMTP żyje w prywatnym pliku POZA katalogiem publicznym /www
 * (ten sam wzorzec co api/newsletter.php + config/mailerlite.php).
 *
 * WAŻNE:
 * - Ten plik NIE zawiera żadnych prawdziwych danych SMTP. Login, hasło
 *   i host żyją wyłącznie w /home/michall/config/smtp.php (poza /www).
 * - Odpowiedź do przeglądarki jest ZAWSZE ogólnym komunikatem — nigdy nie
 *   przekazujemy dalej treści błędu SMTP ani szczegółów technicznych.
 *   Techniczne detale trafiają wyłącznie do error_log() (log serwera).
 * - Adres e-mail wpisany przez użytkownika trafia WYŁĄCZNIE do Reply-To,
 *   nigdy do From — From zawsze pochodzi z uwierzytelnionej skrzynki SMTP
 *   (patrz sekcja 19 briefu: wielu dostawców SMTP, w tym OVH, odrzuca albo
 *   oznacza jako spam wiadomości, w których From nie zgadza się z kontem,
 *   przez które wysyłane jest połączenie).
 */

declare(strict_types=1);

require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

header('Content-Type: application/json; charset=utf-8');
// Ten endpoint jest wywoływany wyłącznie z tej samej strony (fetch z
// js/main.js) — nie ma potrzeby ustawiać nagłówków CORS dla obcych domen.
header('X-Content-Type-Options: nosniff');

/**
 * Jedna, spójna funkcja do kończenia requestu — gwarantuje, że zawsze
 * wychodzi stąd czysty JSON z ustawionym kodem HTTP, nigdy stack trace/
 * ostrzeżenie PHP wyciekające do klienta. Ten sam wzorzec co
 * api/newsletter.php.
 */
function respond(int $httpCode, bool $success, ?string $message = null): never
{
    http_response_code($httpCode);
    $body = ['success' => $success];
    if ($message !== null) {
        $body['message'] = $message;
    }
    echo json_encode($body);
    exit;
}

/**
 * Obcina długość i usuwa znaki sterujące (w tym \r\n) z pojedynczej linii
 * inputu — nawet mimo że PHPMailer sam zabezpiecza nagłówki (addReplyTo()
 * itp.), warto potraktować to jako dodatkową, niezależną warstwę ochrony
 * przed header injection, zamiast polegać wyłącznie na bibliotece.
 */
function sanitizeLine(string $value, int $maxLength): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }
    return substr($value, 0, $maxLength);
}

// --------------------------------------------------------------------
// 1. Tylko POST
// --------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, false, 'Nie udało się wysłać wiadomości. Spróbuj ponownie za chwilę.');
}

// --------------------------------------------------------------------
// 2. Odczyt danych — frontend wysyła JSON (fetch + Content-Type:
//    application/json, patrz submitForm() w js/main.js), z fallbackiem
//    na zwykłe form-encoded (natywny <form> submit bez JS).
// --------------------------------------------------------------------
$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

// --------------------------------------------------------------------
// 3. Honeypot — pole "website" istnieje w formularzu jako pułapka na boty
//    (ukryte przed prawdziwymi użytkownikami, patrz .hp-field w HTML/CSS,
//    id="ctWebsite" / name="website"). Prawdziwy użytkownik nigdy go nie
//    wypełni. Jeśli wypełnione: cichy, pozorny sukces, bez wysyłki maila.
// --------------------------------------------------------------------
$honeypot = trim((string) ($data['website'] ?? ''));
if ($honeypot !== '') {
    respond(200, true);
}

// --------------------------------------------------------------------
// 4. Walidacja wymaganych pól (server-side — nigdy nie ufamy walidacji
//    z frontu). Wymagane w HTML: name, email, message, consent.
//    Firma (company) jest opcjonalna, tak jak w formularzu.
// --------------------------------------------------------------------
$name = sanitizeLine((string) ($data['name'] ?? ''), 200);
$email = sanitizeLine((string) ($data['email'] ?? ''), 200);
$company = sanitizeLine((string) ($data['company'] ?? ''), 200);
$message = trim((string) ($data['message'] ?? ''));
$consent = $data['consent'] ?? null;

if ($name === '') {
    respond(422, false, 'Uzupełnij imię i nazwisko.');
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, false, 'Podaj poprawny adres e-mail.');
}

if ($message === '') {
    respond(422, false, 'Napisz kilka słów o temacie konsultacji.');
}
// Wiadomość może zawierać wiele linii (textarea) — usuwamy tylko znaki
// sterujące poza \n, obcinamy długość, żeby nie przyjąć nieskończonego inputu.
$message = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $message) ?? '';
$message = function_exists('mb_substr') ? mb_substr($message, 0, 5000) : substr($message, 0, 5000);

// Zgoda RODO — checkbox musi być zaznaczony (frontend wysyła "on"/true,
// zależnie od tego czy request przyszedł jako JSON czy form-encoded).
$consentGiven = $consent === 'on' || $consent === true || $consent === '1' || $consent === 1;
if (!$consentGiven) {
    respond(422, false, 'Zaznacz zgodę na przetwarzanie danych osobowych.');
}

// --------------------------------------------------------------------
// 5. Konfiguracja SMTP — plik POZA katalogiem publicznym /www.
//    Docelowa struktura na OVH:
//      /home/michall/config/smtp.php          (prywatny, poza /www)
//      /home/michall/www/api/send-mail.php    (ten plik)
//    __DIR__ tutaj = .../www/api, więc dirname(__DIR__, 2) = .../ (rodzic
//    www), a stąd /config/smtp.php — identyczny mechanizm co
//    api/newsletter.php + config/mailerlite.php.
// --------------------------------------------------------------------
$configPath = dirname(__DIR__, 2) . '/config/smtp.php';

if (!is_file($configPath)) {
    error_log('[send-mail.php] Brak pliku konfiguracyjnego SMTP pod: ' . $configPath);
    respond(500, false, 'Nie udało się wysłać wiadomości. Spróbuj ponownie za chwilę.');
}

/** @var array<string,mixed> $config */
$config = require $configPath;
if (!is_array($config)) {
    $config = [];
}

$smtpHost = trim((string) ($config['SMTP_HOST'] ?? ''));
$smtpPort = (int) ($config['SMTP_PORT'] ?? 465);
$smtpEncryption = trim((string) ($config['SMTP_ENCRYPTION'] ?? 'ssl'));
$smtpUsername = trim((string) ($config['SMTP_USERNAME'] ?? ''));
$smtpPassword = (string) ($config['SMTP_PASSWORD'] ?? '');
$smtpFromEmail = trim((string) ($config['SMTP_FROM_EMAIL'] ?? ''));
$smtpFromName = trim((string) ($config['SMTP_FROM_NAME'] ?? 'Michał Wilkosz'));
$recipient = trim((string) ($config['CONTACT_RECIPIENT'] ?? ''));

if ($smtpHost === '' || $smtpUsername === '' || $smtpPassword === '' || $smtpFromEmail === '' || $recipient === '') {
    error_log('[send-mail.php] Konfiguracja SMTP niekompletna w ' . $configPath);
    respond(500, false, 'Nie udało się wysłać wiadomości. Spróbuj ponownie za chwilę.');
}

// --------------------------------------------------------------------
// 6. Budowa i wysyłka wiadomości przez PHPMailer + uwierzytelnione SMTP.
//    From = adres skrzynki SMTP (nigdy e-mail użytkownika — patrz opis
//    na górze pliku). Reply-To = e-mail podany w formularzu, żeby Michał
//    mógł kliknąć "Odpowiedz" w Gmailu i trafić bezpośrednio do nadawcy.
// --------------------------------------------------------------------
$subject = 'Nowe zapytanie z michalwilkosz.eu — ' . $name;

$bodyLines = [
    'Nowe zapytanie ze strony michalwilkosz.eu',
    '',
    'Imię i nazwisko:',
    $name,
    '',
    'E-mail:',
    $email,
    '',
    'Firma:',
    $company !== '' ? $company : '—',
    '',
    'Temat konsultacji:',
    $message,
    '',
    '---',
    '',
    'Źródło:',
    'michalwilkosz.eu',
];
$body = implode("\n", $bodyLines);

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUsername;
    $mail->Password = $smtpPassword;
    $mail->SMTPSecure = $smtpEncryption === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $smtpPort;
    $mail->CharSet = PHPMailer::CHARSET_UTF8;

    $mail->setFrom($smtpFromEmail, $smtpFromName);
    $mail->addAddress($recipient);
    $mail->addReplyTo($email, $name);

    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body = $body;

    $mail->send();
    respond(200, true);
} catch (PHPMailerException $e) {
    // $mail->ErrorInfo / $e->getMessage() mogą zawierać szczegóły serwera
    // SMTP — logujemy je TYLKO server-side, nigdy do przeglądarki. Hasło
    // SMTP nie trafia do tego komunikatu (PHPMailer go nie ujawnia w
    // ErrorInfo), ale i tak nigdy nie przekazujemy tego dalej do klienta.
    error_log('[send-mail.php] Błąd wysyłki SMTP: ' . $mail->ErrorInfo);
    respond(502, false, 'Nie udało się wysłać wiadomości. Spróbuj ponownie za chwilę.');
} catch (Throwable $e) {
    error_log('[send-mail.php] Nieoczekiwany błąd: ' . $e->getMessage());
    respond(500, false, 'Nie udało się wysłać wiadomości. Spróbuj ponownie za chwilę.');
}
