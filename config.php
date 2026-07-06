<?php
/**
 * config.php
 * Central configuration. Included by every server-side script.
 * NOTHING in this file is ever echoed to the browser.
 */

// ---- Error handling: never leak stack traces / paths to the client ----
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php-error.log');

// ---- Session hardening ----
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
// ini_set('session.cookie_secure', '1'); // enable once served over HTTPS
session_name('exam_portal_sid');
session_start();

// ---- Database ----
define('DB_HOST', getenv('EXAM_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('EXAM_DB_NAME') ?: 'exam_portal');
define('DB_USER', getenv('EXAM_DB_USER') ?: 'exam_app');
define('DB_PASS', getenv('EXAM_DB_PASS') ?: 'CHANGE_ME');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// ---- Shared secret with the local Python AI engine ----
// MUST equal PROCTOR_SHARED_SECRET in app.py. Load from env in production;
// never hardcode a real secret in a file that might be committed to git.
define('PROCTOR_SHARED_SECRET', getenv('PROCTOR_SHARED_SECRET') ?: 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET');

// ---- Local AI engine base URL ----
define('PROCTOR_ENGINE_URL', getenv('PROCTOR_ENGINE_URL') ?: 'http://127.0.0.1:5000');

// ---- Login gate key (demo) ----
define('PORTAL_ACCESS_KEY', getenv('PORTAL_ACCESS_KEY') ?: 'EXAM2026');

// ---- Question banks live ONLY on the server. The correct-answer index is
// stripped before anything is ever sent to the browser (see get_question.php)
function question_banks(): array {
    return [
        'hardware' => [
            'label' => 'Introduction to Computer Hardware and Software',
            'questions' => [
                ['q' => 'What was the main goal of making a computer?', 'opts' => ['To play video games', 'To save human time and energy', 'To write on paper ledgers by hand', 'To take up a lot of physical space'], 'correct' => 1],
                ['q' => 'In the IPO cycle, what does "IPO" stand for?', 'opts' => ['Internal – Power – Option', 'Electronic – Data – Processing', 'Input – Process – Output', 'Ink – Paper – Office'], 'correct' => 2],
                ['q' => 'What is a computer?', 'opts' => ['A paper book', 'An electronic machine', 'A plastic toy', 'A bicycle'], 'correct' => 1],
                ['q' => 'Computers can solve big mathematical problems very ________.', 'opts' => ['Slow', 'Fast', 'Wrong', 'Hard'], 'correct' => 1],
                ['q' => 'Who is known as the "Father of the Computer"?', 'opts' => ['Herman Hollerith', 'Thomas de Colmar', 'Charles Babbage', 'Blaise Pascal'], 'correct' => 2],
                ['q' => 'What was the main component used in the First Generation of computers (1940–1956)?', 'opts' => ['Transistors', 'Vacuum Tubes', 'Integrated Circuits (IC)', 'Microprocessors'], 'correct' => 1],
                ['q' => 'In which computer generation were keyboards and monitors first introduced instead of punch cards?', 'opts' => ['First Generation', 'Second Generation', 'Third Generation', 'Fourth Generation'], 'correct' => 2],
                ['q' => 'Which type of memory is temporary and loses all information when power is turned off?', 'opts' => ['ROM', 'RAM', 'GPU', 'Motherboard'], 'correct' => 1],
                ['q' => 'Which of the following is an input device?', 'opts' => ['Monitor', 'Printer', 'Mouse', 'Speakers'], 'correct' => 2],
                ['q' => 'Which digital port is commonly used to transmit high-quality video and audio to modern monitors or televisions?', 'opts' => ['VGA Port', 'LAN Port', 'Audio Port', 'HDMI Port'], 'correct' => 3],
            ],
        ],
        'wordpress' => [
            'label' => 'WordPress Fundamentals',
            'questions' => [
                ['q' => 'Which protocol provides a secure and encrypted connection for websites?', 'opts' => ['HTTP', 'HTTPS', 'FTP', 'SSH'], 'correct' => 1],
                ['q' => 'What is the text-based address that users type into a web browser to visit a website?', 'opts' => ['IP Address', 'Domain Name', 'Database', 'Web Port'], 'correct' => 1],
                ['q' => 'What is the complete address of a specific web page called?', 'opts' => ['URL (Uniform Resource Locator)', 'RAM', 'CPU', 'TLD'], 'correct' => 0],
                ['q' => 'What does the extension of a domain name (such as .com or .org) represent?', 'opts' => ['Subdomain', 'SSL Certificate', 'TLD (Top-Level Domain)', 'Localhost'], 'correct' => 2],
                ['q' => 'What is the child part of a main domain called (e.g., blog.example.com)?', 'opts' => ['Web Server', 'Subdomain', 'IP Address', 'Meta Tag'], 'correct' => 1],
                ['q' => 'What is the unique numerical address assigned to every device connected to the internet?', 'opts' => ['Website Slug', 'Domain Name', 'Cookie', 'IP Address'], 'correct' => 3],
                ['q' => 'Which component stores website files and delivers them to users over the internet?', 'opts' => ['Web Server', 'Web Browser', 'Local Storage', 'GPU'], 'correct' => 0],
                ['q' => 'When a developer builds and tests a website on their personal computer, it is running on:', 'opts' => ['Cloud Hosting', 'Localhost', 'Production Environment', 'CDN'], 'correct' => 1],
                ['q' => 'Which HTTP status code indicates that a requested web page does not exist?', 'opts' => ['200 OK', '301 Moved Permanently', '404 Not Found', '503 Service Unavailable'], 'correct' => 2],
                ['q' => 'What are the small text files stored in a user\'s web browser to remember settings and login sessions?', 'opts' => ['Cookies', 'Hard Drives', 'Firewalls', 'DNS Records'], 'correct' => 0],
            ],
        ],
    ];
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function require_candidate_session(): array {
    if (empty($_SESSION['candidate']) || empty($_SESSION['exam_session_id'])) {
        json_response(['ok' => false, 'error' => 'No active exam session'], 401);
    }
    return $_SESSION['candidate'];
}
