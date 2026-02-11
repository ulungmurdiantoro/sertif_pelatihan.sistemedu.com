<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// =========================
// CORS (LOCAL + PROD)
// =========================
$allowed = [
  "http://localhost:5173",
  "https://sertif-pelatihan.sistemedu.com",
  "https://www.sertif-pelatihan.sistemedu.com",
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Preflight
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

// =========================
// METHOD GUARD
// =========================
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  respond(false, "Gunakan POST.");
}

// =========================
// CHECK shell_exec (shared hosting sering disable)
// =========================
if (!function_exists("shell_exec")) {
  respond(false, "shell_exec tidak tersedia (kemungkinan diblok hosting).");
}
$disabled = ini_get("disable_functions") ?: "";
if ($disabled && stripos($disabled, "shell_exec") !== false) {
  respond(false, "shell_exec di-disable oleh hosting: " . $disabled);
}

// =========================
// PATHS (sesuai struktur folder Anda)
// base = folder /Sertif_Pelatihan
// =========================
$base = realpath(__DIR__ . "/..");
if (!$base) respond(false, "Base path tidak valid.");

$uploadDir   = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "uploads";
$outputRoot  = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "output";
$downloadDir = $base . DIRECTORY_SEPARATOR . "web" . DIRECTORY_SEPARATOR . "download";

@mkdir($uploadDir, 0777, true);
@mkdir($outputRoot, 0777, true);
@mkdir($downloadDir, 0777, true);

if (!is_writable($uploadDir))  respond(false, "uploads tidak writable: $uploadDir");
if (!is_writable($outputRoot)) respond(false, "output tidak writable: $outputRoot");
if (!is_writable($downloadDir))respond(false, "download tidak writable: $downloadDir");

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
// PYTHON PATH (support .venv & venv, Linux & Windows)
// =========================
$pythonCandidates = [
  $base . DIRECTORY_SEPARATOR . ".venv" . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "python",      // Linux .venv
  $base . DIRECTORY_SEPARATOR . "venv"  . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "python",      // Linux venv
  $base . DIRECTORY_SEPARATOR . ".venv" . DIRECTORY_SEPARATOR . "Scripts" . DIRECTORY_SEPARATOR . "python.exe", // Windows .venv
  $base . DIRECTORY_SEPARATOR . "venv"  . DIRECTORY_SEPARATOR . "Scripts" . DIRECTORY_SEPARATOR . "python.exe", // Windows venv
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

$tplArg = implode("|", $templatePaths);

$cmd =
  escapeshellarg($python) . " " .
  escapeshellarg($script) .
  " --templates " . escapeshellarg($tplArg) .
  " --data " . escapeshellarg($dataPath) .
  " --outdir " . escapeshellarg($jobOutDir) .
  " 2>&1";

$out = shell_exec($cmd);

// =========================
// COLLECT PDF
// =========================
$pdfs = glob($jobOutDir . DIRECTORY_SEPARATOR . "*.pdf");
if (!$pdfs) {
  rrmdir($jobOutDir);
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);

  respond(false, "Tidak ada PDF dihasilkan.", [], [
    "debug" => [
      "python" => $python,
      "cmd" => $cmd,
      "python_output" => trim((string)$out),
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

  $pdfLinks[] = "/Sertif_Pelatihan/web/download/" . $cleanName;
  $pdfPublicAbs[] = $dest;
}

// zip
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
      "python" => $python,
      "cmd" => $cmd,
      "python_output" => trim((string)$out),
    ]
  ]);
}

// cleanup internal
rrmdir($jobOutDir);
@unlink($dataPath);
foreach ($templatePaths as $tp) @unlink($tp);

// response
respond(true, "Selesai generate.", array_merge([$zipLink], $pdfLinks));
