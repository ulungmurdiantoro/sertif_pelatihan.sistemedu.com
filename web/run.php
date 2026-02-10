<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { exit; }

$base = realpath(__DIR__ . "/..");

$uploadDir   = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "uploads";
$outputRoot  = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "output";
$downloadDir = $base . DIRECTORY_SEPARATOR . "web" . DIRECTORY_SEPARATOR . "download";

@mkdir($uploadDir, 0777, true);
@mkdir($outputRoot, 0777, true);
@mkdir($downloadDir, 0777, true);

function respond($ok, $message, $files = []) {
  echo json_encode(["ok"=>$ok, "message"=>$message, "files"=>$files], JSON_UNESCAPED_SLASHES);
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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  respond(false, "Gunakan POST.");
}

if (!isset($_FILES["templates"], $_FILES["data"])) {
  respond(false, "templates[] dan data wajib diupload.");
}

$data = $_FILES["data"];
$templates = $_FILES["templates"];

// validate data
if (strtolower(pathinfo($data["name"], PATHINFO_EXTENSION)) !== "xlsx") {
  respond(false, "Data harus .xlsx");
}

// validate templates
if (!isset($templates["name"]) || count($templates["name"]) < 1) {
  respond(false, "Minimal 1 template.");
}

for ($i = 0; $i < count($templates["name"]); $i++) {
  $ext = strtolower(pathinfo($templates["name"][$i], PATHINFO_EXTENSION));
  if (!in_array($ext, ["png","jpg","jpeg"])) {
    respond(false, "Template harus PNG/JPG");
  }
}

$stamp = date("Ymd_His") . "_" . bin2hex(random_bytes(4));

// simpan data
$dataPath = $uploadDir . DIRECTORY_SEPARATOR . "data_{$stamp}.xlsx";
if (!move_uploaded_file($data["tmp_name"], $dataPath)) {
  respond(false, "Gagal simpan data.");
}

// simpan templates
$templatePaths = [];
for ($i = 0; $i < count($templates["name"]); $i++) {
  $ext = strtolower(pathinfo($templates["name"][$i], PATHINFO_EXTENSION));
  $savePath = $uploadDir . DIRECTORY_SEPARATOR . "template_{$stamp}_p" . ($i+1) . "." . $ext;
  move_uploaded_file($templates["tmp_name"][$i], $savePath);
  $templatePaths[] = $savePath;
}

// python
$pythonVenv = $base . DIRECTORY_SEPARATOR . "venv" . DIRECTORY_SEPARATOR . "Scripts" . DIRECTORY_SEPARATOR . "python.exe";
$python = file_exists($pythonVenv) ? $pythonVenv : "python";
$script = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "generate.py";

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

shell_exec($cmd);

// ambil pdf
$pdfs = glob($jobOutDir . DIRECTORY_SEPARATOR . "*.pdf");
if (!$pdfs) {
  rrmdir($jobOutDir);
  respond(false, "Tidak ada PDF dihasilkan.");
}

// copy PDF ke publik (NAMA BERSIH)
$pdfLinks = [];
$pdfPublicAbs = [];

foreach ($pdfs as $p) {
  $cleanName = basename($p); // SERTIFIKAT_Nama.pdf
  $dest = $downloadDir . DIRECTORY_SEPARATOR . $cleanName;

  // overwrite aman karena per job terpisah
  @copy($p, $dest);

  $pdfLinks[] = "/Sertif_Pelatihan/web/download/" . $cleanName;
  $pdfPublicAbs[] = $dest;
}

// buat ZIP
$zipName = "SERTIFIKAT_" . $stamp . ".zip";
$zipAbs  = $downloadDir . DIRECTORY_SEPARATOR . $zipName;
$zipLink = "/Sertif_Pelatihan/web/download/" . $zipName;

make_zip($zipAbs, $pdfPublicAbs);

// bersihkan internal
rrmdir($jobOutDir);
@unlink($dataPath);
foreach ($templatePaths as $tp) @unlink($tp);

// response (ZIP dulu, lalu PDF)
respond(true, "Selesai generate.", array_merge([$zipLink], $pdfLinks));
