<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// =========================
// CORS (PROD + LOCAL)
// =========================
$allowed_origins = [
  "http://localhost:5173",
  "https://sertif-pelatihan.sistemedu.com",
  // tambahkan domain frontend lain jika ada:
  // "https://frontend-anda.com",
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowed_origins, true)) {
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
  $payload = array_merge([
    "ok"      => (bool)$ok,
    "message" => (string)$message,
    "files"   => (array)$files
  ], $extra);

  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

// =========================
// METHOD GUARD
// =========================
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  respond(false, "Gunakan POST.");
}

// =========================
// BASIC SERVER ENV CHECKS (cPanel)
// =========================
if (!function_exists('shell_exec')) {
  respond(false, "shell_exec tidak tersedia (function tidak ada). Biasanya diblok hosting.");
}
$disabled = ini_get('disable_functions') ?: '';
if ($disabled && stripos($disabled, 'shell_exec') !== false) {
  respond(false, "shell_exec di-disable oleh hosting: $disabled");
}

// =========================
// PATHS
// =========================
$base = realpath(__DIR__ . "/..");
if (!$base) {
  respond(false, "Base path tidak valid.");
}

$uploadDir   = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "uploads";
$outputRoot  = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "output";
$downloadDir = $base . DIRECTORY_SEPARATOR . "web" . DIRECTORY_SEPARATOR . "download";

@mkdir($uploadDir, 0777, true);
@mkdir($outputRoot, 0777, true);
@mkdir($downloadDir, 0777, true);

if (!is_writable($uploadDir)) {
  respond(false, "Folder uploads tidak writable: $uploadDir");
}
if (!is_writable($outputRoot)) {
  respond(false, "Folder output tidak writable: $outputRoot");
}
if (!is_writable($downloadDir)) {
  respond(false, "Folder download tidak writable: $downloadDir");
}

// =========================
// FILES VALIDATION
// =========================
if (!isset($_FILES["data"], $_FILES["templates"])) {
  respond(false, "templates[] dan data wajib diupload.");
}

$data = $_FILES["data"];
$templates = $_FILES["templates"];

// validate data upload error
if (!isset($data["error"]) || $data["error"] !== UPLOAD_ERR_OK) {
  respond(false, "Upload data Excel gagal. Kode error: " . ($data["error"] ?? "unknown"));
}
if (strtolower(pathinfo($data["name"], PATHINFO_EXTENSION)) !== "xlsx") {
  respond(false, "Data harus .xlsx");
}

// validate templates
if (!isset($templates["name"]) || !is_array($templates["name"]) || count($templates["name"]) < 1) {
  respond(false, "Minimal 1 template.");
}

// cek error templates
for ($i = 0; $i < count($templates["name"]); $i++) {
  $err = $templates["error"][$i] ?? UPLOAD_ERR_NO_FILE;
  if ($err !== UPLOAD_ERR_OK) {
    respond(false, "Upload template ke-" . ($i+1) . " gagal. Kode error: $err");
  }
  $ext = strtolower(pathinfo($templates["name"][$i], PATHINFO_EXTENSION));
  if (!in_array($ext, ["png", "jpg", "jpeg"], true)) {
    respond(false, "Template harus PNG/JPG");
  }
}

// =========================
// SAVE UPLOADS
// =========================
$stamp = date("Ymd_His") . "_" . bin2hex(random_bytes(4));

// simpan data
$dataPath = $uploadDir . DIRECTORY_SEPARATOR . "data_{$stamp}.xlsx";
if (!move_uploaded_file($data["tmp_name"], $dataPath)) {
  respond(false, "Gagal simpan data Excel.");
}

// simpan templates
$templatePaths = [];
for ($i = 0; $i < count($templates["name"]); $i++) {
  $ext = strtolower(pathinfo($templates["name"][$i], PATHINFO_EXTENSION));
  $savePath = $uploadDir . DIRECTORY_SEPARATOR . "template_{$stamp}_p" . ($i + 1) . "." . $ext;

  if (!move_uploaded_file($templates["tmp_name"][$i], $savePath)) {
    // bersihkan data yg sudah tersimpan
    @unlink($dataPath);
    foreach ($templatePaths as $tp) @unlink($tp);
    respond(false, "Gagal simpan template ke-" . ($i+1));
  }
  $templatePaths[] = $savePath;
}

// =========================
// RUN PYTHON (LINUX FRIENDLY)
// =========================
$pythonVenvLinux = $base . DIRECTORY_SEPARATOR . "venv" . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "python";
$pythonVenvWin   = $base . DIRECTORY_SEPARATOR . "venv" . DIRECTORY_SEPARATOR . "Scripts" . DIRECTORY_SEPARATOR . "python.exe";

if (file_exists($pythonVenvLinux)) {
  $python = $pythonVenvLinux;
} elseif (file_exists($pythonVenvWin)) {
  $python = $pythonVenvWin;
} else {
  $python = "python3"; // fallback di cPanel umumnya python3
}

$script = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "generate.py";
if (!file_exists($script)) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);
  respond(false, "Script generate.py tidak ditemukan: $script");
}

$jobOutDir = $outputRoot . DIRECTORY_SEPARATOR . "job_" . $stamp;
@mkdir($jobOutDir, 0777, true);

$tplArg = implode("|", $templatePaths);

$cmd =
  escapeshellarg($python) . " " .
  escapeshellarg($script) .
  " --templates " . escapeshellarg($tplArg) .
  " --data " . escapeshellarg($dataPath) .
  " --outdir " . escapeshellarg($jobOutDir) .
  " 2>&1";

// Ambil output untuk debugging
$out = shell_exec($cmd);

// =========================
// COLLECT OUTPUT PDF
// =========================
$pdfs = glob($jobOutDir . DIRECTORY_SEPARATOR . "*.pdf");
if (!$pdfs) {
  rrmdir($jobOutDir);
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);

  // kirim output python biar kelihatan errornya
  respond(false, "Tidak ada PDF dihasilkan.", [], [
    "debug" => [
      "cmd" => $cmd,
      "python_output" => trim((string)$out),
      "jobOutDir" => $jobOutDir,
    ]
  ]);
}

// copy PDF ke publik
$pdfLinks = [];
$pdfPublicAbs = [];

foreach ($pdfs as $p) {
  $cleanName = basename($p);
  $dest = $downloadDir . DIRECTORY_SEPARATOR . $cleanName;

  @copy($p, $dest);

  $pdfLinks[] = "/Sertif_Pelatihan/web/download/" . $cleanName;
  $pdfPublicAbs[] = $dest;
}

// buat ZIP
$zipName = "SERTIFIKAT_" . $stamp . ".zip";
$zipAbs  = $downloadDir . DIRECTORY_SEPARATOR . $zipName;
$zipLink = "/Sertif_Pelatihan/web/download/" . $zipName;

try {
  make_zip($zipAbs, $pdfPublicAbs);
} catch (Exception $e) {
  rrmdir($jobOutDir);
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);
  respond(false, "Gagal membuat ZIP: " . $e->getMessage(), [], [
    "debug" => [
      "cmd" => $cmd,
      "python_output" => trim((string)$out),
    ]
  ]);
}

// bersihkan internal
rrmdir($jobOutDir);
@unlink($dataPath);
foreach ($templatePaths as $tp) @unlink($tp);

// response (ZIP dulu, lalu PDF)
respond(true, "Selesai generate.", array_merge([$zipLink], $pdfLinks));
