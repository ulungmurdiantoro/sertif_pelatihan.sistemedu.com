<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// =========================
// CORS (LOCAL + PROD)
// =========================
$allowed = [
  "http://localhost:5173",
  "https://sertif.mutuperguruantinggi.id",
  "https://www.sertif.mutuperguruantinggi.id",
  "https://sertif-pelatihan.sistemedu.com",
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(204);
  exit;
}

// =========================
// HELPERS
// =========================
function respond($ok, $message, $files = [], $extra = []) {
  echo json_encode(array_merge([
    "ok" => (bool)$ok,
    "message" => (string)$message,
    "files" => (array)$files
  ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function rrmdir($dir) {
  if (!is_dir($dir)) return;
  foreach (scandir($dir) as $item) {
    if ($item === "." || $item === "..") continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) rrmdir($path);
    else @unlink($path);
  }
  @rmdir($dir);
}

function make_zip($zipPath, $filesAbs) {
  if (!class_exists("ZipArchive")) {
    throw new Exception("ZipArchive tidak tersedia. Aktifkan extension zip di PHP.");
  }
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new Exception("Gagal membuat ZIP.");
  }
  foreach ($filesAbs as $abs) {
    if (!file_exists($abs)) continue;
    $zip->addFile($abs, basename($abs));
  }
  $zip->close();
}

/**
 * Jalankan command dengan timeout (menghindari hang 5+ menit tanpa selesai).
 * Return: [exitCode, output, timedOut]
 */
function run_cmd_with_timeout($cmd, $timeoutSeconds = 180) {
  $descriptorspec = [
    0 => ["pipe", "r"], // stdin
    1 => ["pipe", "w"], // stdout
    2 => ["pipe", "w"], // stderr
  ];

  $process = proc_open($cmd, $descriptorspec, $pipes);
  if (!is_resource($process)) {
    return [999, "Gagal menjalankan proses (proc_open).", false];
  }

  fclose($pipes[0]);
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);

  $output = "";
  $start = time();
  $timedOut = false;

  while (true) {
    $status = proc_get_status($process);
    $running = $status["running"];

    $output .= stream_get_contents($pipes[1]);
    $output .= stream_get_contents($pipes[2]);

    if (!$running) {
      break;
    }

    if ((time() - $start) > $timeoutSeconds) {
      $timedOut = true;
      // coba terminate halus
      proc_terminate($process);
      sleep(1);
      // kalau masih hidup, paksa
      $status2 = proc_get_status($process);
      if ($status2["running"]) {
        proc_terminate($process, 9);
      }
      break;
    }

    usleep(120000); // 0.12s
  }

  $output .= stream_get_contents($pipes[1]);
  $output .= stream_get_contents($pipes[2]);

  fclose($pipes[1]);
  fclose($pipes[2]);

  $exitCode = proc_close($process);
  return [$exitCode, $output, $timedOut];
}

// =========================
// METHOD GUARD
// =========================
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  respond(false, "Gunakan POST.");
}

// =========================
// CHECK command execution availability
// =========================
if (!function_exists("proc_open")) {
  respond(false, "proc_open tidak tersedia (kemungkinan diblok hosting).");
}
$disabled = ini_get("disable_functions") ?: "";
if ($disabled && (stripos($disabled, "proc_open") !== false || stripos($disabled, "proc_terminate") !== false)) {
  respond(false, "proc_open/proc_terminate di-disable oleh hosting: " . $disabled);
}

// =========================
// PATHS (base = public_html)
// =========================
$base = realpath(__DIR__ . "/..");
if (!$base) respond(false, "Base path tidak valid.");

$uploadDir   = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "uploads";
$outputRoot  = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "output";
$downloadDir = $base . DIRECTORY_SEPARATOR . "web" . DIRECTORY_SEPARATOR . "download";

@mkdir($uploadDir, 0777, true);
@mkdir($outputRoot, 0777, true);
@mkdir($downloadDir, 0777, true);

if (!is_writable($uploadDir))   respond(false, "uploads tidak writable: $uploadDir");
if (!is_writable($outputRoot))  respond(false, "output tidak writable: $outputRoot");
if (!is_writable($downloadDir)) respond(false, "download tidak writable: $downloadDir");

// =========================
// FILES VALIDATION
// =========================
if (!isset($_FILES["templates"], $_FILES["data"])) {
  respond(false, "templates[] dan data wajib diupload.");
}

$data = $_FILES["data"];
$templates = $_FILES["templates"];

if (($data["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  respond(false, "Upload data Excel gagal. Kode: " . ($data["error"] ?? "unknown"));
}
if (strtolower(pathinfo($data["name"], PATHINFO_EXTENSION)) !== "xlsx") {
  respond(false, "Data harus .xlsx");
}

if (!isset($templates["name"]) || !is_array($templates["name"]) || count($templates["name"]) < 1) {
  respond(false, "Minimal 1 template.");
}

for ($i = 0; $i < count($templates["name"]); $i++) {
  $err = $templates["error"][$i] ?? UPLOAD_ERR_NO_FILE;
  if ($err !== UPLOAD_ERR_OK) {
    respond(false, "Upload template ke-" . ($i+1) . " gagal. Kode: $err");
  }
  $ext = strtolower(pathinfo($templates["name"][$i], PATHINFO_EXTENSION));
  if (!in_array($ext, ["png","jpg","jpeg"], true)) {
    respond(false, "Template harus PNG/JPG");
  }
}

// =========================
// SAVE UPLOADS
// =========================
$stamp = date("Ymd_His") . "_" . bin2hex(random_bytes(4));

$dataPath = $uploadDir . DIRECTORY_SEPARATOR . "data_{$stamp}.xlsx";
if (!move_uploaded_file($data["tmp_name"], $dataPath)) {
  respond(false, "Gagal simpan data Excel.");
}

$templatePaths = [];
for ($i = 0; $i < count($templates["name"]); $i++) {
  $ext = strtolower(pathinfo($templates["name"][$i], PATHINFO_EXTENSION));
  $savePath = $uploadDir . DIRECTORY_SEPARATOR . "template_{$stamp}_p" . ($i+1) . "." . $ext;

  if (!move_uploaded_file($templates["tmp_name"][$i], $savePath)) {
    @unlink($dataPath);
    foreach ($templatePaths as $tp) @unlink($tp);
    respond(false, "Gagal simpan template ke-" . ($i+1));
  }
  $templatePaths[] = $savePath;
}

// =========================
// PYTHON PATH
// =========================
$pythonCandidates = [
  $base . DIRECTORY_SEPARATOR . ".venv" . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "python",
  $base . DIRECTORY_SEPARATOR . "venv"  . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "python",
  "python3",
  "python",
];

$python = null;
foreach ($pythonCandidates as $cand) {
  if ($cand === "python3" || $cand === "python") { $python = $cand; break; }
  if (file_exists($cand)) { $python = $cand; break; }
}
if (!$python) $python = "python3";

$script = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "generate.py";
if (!file_exists($script)) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);
  respond(false, "generate.py tidak ditemukan: $script");
}

$jobOutDir = $outputRoot . DIRECTORY_SEPARATOR . "job_" . $stamp;
@mkdir($jobOutDir, 0777, true);

// log file untuk cek progress server-side
$logFile = $jobOutDir . DIRECTORY_SEPARATOR . "run.log";

$tplArg = implode("|", $templatePaths);

$cmd =
  escapeshellarg($python) . " " .
  escapeshellarg($script) .
  " --templates " . escapeshellarg($tplArg) .
  " --data " . escapeshellarg($dataPath) .
  " --outdir " . escapeshellarg($jobOutDir);

file_put_contents($logFile, "CMD:\n$cmd\n\n", FILE_APPEND);

// =========================
// RUN PYTHON with TIMEOUT
// =========================
$timeoutSeconds = 180; // 3 menit (ubah sesuai kebutuhan)
$fullCmd = $cmd . " 2>&1";

list($exitCode, $out, $timedOut) = run_cmd_with_timeout($fullCmd, $timeoutSeconds);

file_put_contents($logFile, "EXIT_CODE: $exitCode\nTIMED_OUT: " . ($timedOut ? "YES" : "NO") . "\n\nOUTPUT:\n$out\n", FILE_APPEND);

// =========================
// COLLECT PDF
// =========================
$pdfs = glob($jobOutDir . DIRECTORY_SEPARATOR . "*.pdf");
if (!$pdfs) {
  // bersihkan upload (biar tidak numpuk)
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);

  // kalau timeout, kasih pesan jelas
  if ($timedOut) {
    respond(false, "Proses Python timeout > {$timeoutSeconds}s (hosting lambat/terbatas).", [], [
      "debug" => [
        "python" => $python,
        "cmd" => $cmd,
        "exitCode" => $exitCode,
        "timedOut" => true,
        "python_output" => trim((string)$out),
        "logFile" => $logFile,
        "jobOutDir" => $jobOutDir
      ]
    ]);
  }

  respond(false, "Tidak ada PDF dihasilkan.", [], [
    "debug" => [
      "python" => $python,
      "cmd" => $cmd,
      "exitCode" => $exitCode,
      "timedOut" => false,
      "python_output" => trim((string)$out),
      "logFile" => $logFile,
      "jobOutDir" => $jobOutDir
    ]
  ]);
}

// copy pdf ke publik
$pdfLinks = [];
$pdfPublicAbs = [];
foreach ($pdfs as $p) {
  $cleanName = basename($p);
  $dest = $downloadDir . DIRECTORY_SEPARATOR . $cleanName;
  @copy($p, $dest);

  $pdfLinks[] = "/web/download/" . $cleanName;
  $pdfPublicAbs[] = $dest;
}

// zip
$zipName = "SERTIFIKAT_" . $stamp . ".zip";
$zipAbs  = $downloadDir . DIRECTORY_SEPARATOR . $zipName;
$zipLink = "/web/download/" . $zipName;

try {
  make_zip($zipAbs, $pdfPublicAbs);
} catch (Exception $e) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);

  respond(false, "Gagal membuat ZIP: " . $e->getMessage(), [], [
    "debug" => [
      "python" => $python,
      "cmd" => $cmd,
      "python_output" => trim((string)$out),
      "logFile" => $logFile,
    ]
  ]);
}

// cleanup uploads (output job folder biarkan dulu untuk log, kalau mau bersihkan silakan rrmdir)
@unlink($dataPath);
foreach ($templatePaths as $tp) @unlink($tp);

// OPTIONAL: kalau mau hemat storage, boleh hapus job dir (tapi log juga hilang)
// rrmdir($jobOutDir);

// response
respond(true, "Selesai generate.", array_merge([$zipLink], $pdfLinks), [
  "debug" => [
    "exitCode" => $exitCode,
    "timedOut" => $timedOut,
    "logFile" => $logFile
  ]
]);
