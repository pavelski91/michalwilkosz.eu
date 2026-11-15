<?php
/**
 * MICHAŁ WILKOSZ — michalwilkosz.eu
 * api/newsletter.php
 *
 * Backend endpoint dla formularza newslettera (#newsletterForm, js/main.js).
 * Przyjmuje e-mail z frontendu, waliduje go, i zapisuje subskrybenta przez
 * MailerLite API do grupy skonfigurowanej w prywatnym pliku config
 * (POZA katalogiem publicznym /www — patrz $configPath niżej).
 *
 * WAŻNE:
 * - Ten plik NIE zawiera żadnego prawdziwego tokena API. Token i ID grupy
 *   żyją wyłącznie w /home/michall/config/mailerlite.php (poza /www).
 * - Odpowiedź do przeglądarki jest ZAWSZE ogólnym komunikatem — nigdy nie
 *   przekazujemy dalej surowej odpowiedzi MailerLite ani szczegółów błędu.
 *   Techniczne detale trafiają wyłącznie do error_log() (log serwera).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// Ten endpoint jest wywoływany wyłącznie z tej samej strony (fetch z
// js/main.js) — nie ma potrzeby ustawiać nagłówków CORS dla obcych domen.
header('X-Content-Type-Options: nosniff');

/**
 * Jedna, spójna funkcja do kończenia requestu — gwarantuje, że zawsze
 * wychodzi stąd czysty JSON z ustawionym kodem HTTP, nigdy stack trace/
 * ostrzeżenie PHP wyciekające do klienta.
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

// --------------------------------------------------------------------
// 1. Tylko POST
// --------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, false, 'Nie udało się zapisać. Spróbuj ponownie.');
}

// --------------------------------------------------------------------
// 2. Odczyt danych — frontend wysyła JSON (fetch + Content-Type:
//    application/json, patrz submitForm() w js/main.js), ale przyjmujemy
//    też zwykłe form-encoded jako fallback (np. natywny <form> submit
//    bez JS, zgodnie z resztą strony, która ma działać bez JS-a).
// --------------------------------------------------------------------
$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

// --------------------------------------------------------------------
// 3. Honeypot — pole "company" istnieje w formularzu jako pułapka na boty
//    (ukryte przed prawdziwymi użytkownikami, patrz .hp-field w HTML/CSS).
//    Prawdziwy użytkownik nigdy go nie wypełni. Jeśli wypełnione: cichy,
//    pozorny sukces (bot nie powinien wiedzieć, że został wykryty), bez
//    żadnego zapytania do MailerLite.
// --------------------------------------------------------------------
$honeypot = trim((string) ($data['company'] ?? ''));
if ($honeypot !== '') {
    respond(200, true);
}

// --------------------------------------------------------------------
// 4. Walidacja e-maila (server-side — nigdy nie ufamy walidacji z frontu)
// --------------------------------------------------------------------
$email = trim((string) ($data['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, false, 'Podaj poprawny adres e-mail.');
}

// --------------------------------------------------------------------
// 5. Konfiguracja MailerLite — plik POZA katalogiem publicznym /www.
//    Docelowa struktura na OVH:
//      /home/michall/config/mailerlite.php   (prywatny, poza /www)
//      /home/michall/www/api/newsletter.php  (ten plik)
//    __DIR__ tutaj = .../www/api, więc dirname(__DIR__, 2) = .../ (rodzic
//    www), a stąd /config/mailerlite.php.
// --------------------------------------------------------------------
$configPath = dirname(__DIR__, 2) . '/config/mailerlite.php';

if (!is_file($configPath)) {
    error_log('[newsletter.php] Brak pliku konfiguracyjnego MailerLite pod: ' . $configPath);
    respond(500, false, 'Nie udało się zapisać. Spróbuj ponownie.');
}

/** @var array<string,string> $config */
$config = require $configPath;

$apiToken = is_array($config) ? trim((string) ($config['MAILERLITE_API_TOKEN'] ?? '')) : '';
$groupId = is_array($config) ? trim((string) ($config['MAILERLITE_GROUP_ID'] ?? '')) : '';

if ($apiToken === '' || $groupId === '') {
    error_log('[newsletter.php] MAILERLITE_API_TOKEN lub MAILERLITE_GROUP_ID nie są uzupełnione w ' . $configPath);
    respond(500, false, 'Nie udało się zapisać. Spróbuj ponownie.');
}

// --------------------------------------------------------------------
// 6. Zapytanie do MailerLite API
//    POST https://connect.mailerlite.com/api/subscribers
//    — dodaje subskrybenta (albo aktualizuje istniejącego) i przypisuje
//    go do podanej grupy w jednym wywołaniu.
// --------------------------------------------------------------------
$payload = json_encode([
    'email' => $email,
    'groups' => [$groupId],
]);

if ($payload === false) {
    error_log('[newsletter.php] Nie udało się zbudować payloadu JSON dla MailerLite.');
    respond(500, false, 'Nie udało się zapisać. Spróbuj ponownie.');
}

$ch = curl_init('https://connect.mailerlite.com/api/subscribers');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        // Token NIGDY nie trafia do logów — tylko w tym nagłówku, w locie.
        'Authorization: Bearer ' . $apiToken,
    ],
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 12,
]);

$responseBody = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
curl_close($ch);

// --------------------------------------------------------------------
// 7. Obsługa wyniku — użytkownik dostaje wyłącznie success/ogólny błąd,
//    nigdy treść odpowiedzi MailerLite ani szczegóły techniczne.
// --------------------------------------------------------------------
if ($curlErrno !== 0) {
    // Log NIE zawiera tokena — $curlError to komunikat sieciowy cURL
    // (np. "Could not resolve host"), nie ma w nim nagłówków requestu.
    error_log('[newsletter.php] Błąd połączenia z MailerLite (curl errno ' . $curlErrno . '): ' . $curlError);
    respond(502, false, 'Nie udało się zapisać. Spróbuj ponownie.');
}

if ($httpCode >= 200 && $httpCode < 300) {
    respond(200, true);
}

// Każdy inny kod (401 zły token, 422 nieprawidłowe dane wg MailerLite,
// 429 rate limit, 5xx po stronie MailerLite) — log techniczny na
// serwerze, ogólny komunikat dla użytkownika.
error_log('[newsletter.php] MailerLite API zwróciło HTTP ' . $httpCode . ': ' . (string) $responseBody);
respond(502, false, 'Nie udało się zapisać. Spróbuj ponownie.');
