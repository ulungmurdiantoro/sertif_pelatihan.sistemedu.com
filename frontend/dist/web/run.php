<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // jangan bocorkan HTML warning ke fetch
header('Content-Type: application/json; charset=utf-8');

// =========================
// CORS + OPTIONS
// =========================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if (($_SERVER["REQUEST_METHOD"] ?? '') === "OPTIONS") {
  http_response_code(204);
  exit;
}

// =========================
// HELPERS
// =========================
function respond(bool $ok, string $message, array $files = [], array $extra = []): void {
  http_response_code($ok ? 200 : 400);
  echo json_encode(array_merge([
    "ok" => $ok,
    "message" => $message,
    "files" => $files,
  ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function sanitize_filename(string $s): string {
  $keep = " _-().,";
  $out = "";
  $s = trim($s);
  $len = mb_strlen($s, 'UTF-8');
  for ($i = 0; $i < $len; $i++) {
    $ch = mb_substr($s, $i, 1, 'UTF-8');
    if (ctype_alnum($ch) || strpos($keep, $ch) !== false) $out .= $ch;
  }
  $out = trim($out);
  return $out !== "" ? $out : "NONAME";
}

function rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  foreach (scandir($dir) as $item) {
    if ($item === "." || $item === "..") continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) rrmdir($path);
    else @unlink($path);
  }
  @rmdir($dir);
}

function make_zip(string $zipPath, array $filesAbs): void {
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
 * Convert pt -> mm (ReportLab positions to mPDF positions)
 */
function pt_to_mm(float $pt): float {
  return $pt * 25.4 / 72.0;
}

// =========================
// METHOD GUARD
// =========================
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  respond(false, "Gunakan POST.");
}

// =========================
// BASE PATHS (RUN.PHP located at frontend/dist/web)
// Root project is 3 levels up: dist/web -> dist -> frontend -> root project
// =========================
$base = realpath(__DIR__ . "/../../..");
if (!$base) {
  respond(false, "Base path tidak valid.", [], [
    "debug" => [
      "where" => "__DIR__=" . __DIR__,
      "computed_base" => __DIR__ . "/../../..",
    ]
  ]);
}

// engine paths
$uploadDir  = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "uploads";
$outputRoot = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "output";

// download public path (inside dist/web)
$downloadDir = __DIR__ . DIRECTORY_SEPARATOR . "download";

@mkdir($uploadDir, 0777, true);
@mkdir($outputRoot, 0777, true);
@mkdir($downloadDir, 0777, true);

if (!is_writable($uploadDir))  respond(false, "uploads tidak writable: $uploadDir");
if (!is_writable($outputRoot)) respond(false, "output tidak writable: $outputRoot");
if (!is_writable($downloadDir)) respond(false, "download tidak writable: $downloadDir");

// =========================
// COMPOSER AUTOLOAD (engine/vendor)
// =========================
$autoload = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";
if (!file_exists($autoload)) {
  respond(false, "Gagal generate PDF: vendor/autoload.php tidak ditemukan. Jalankan composer install (mpdf + phpspreadsheet).", [], [
    "debug" => [
      "expected_autoload" => $autoload,
      "base" => $base,
    ]
  ]);
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

// =========================
// VALIDATE UPLOADS
// =========================
if (!isset($_FILES["templates"], $_FILES["data"])) {
  respond(false, "templates[] dan data wajib diupload.");
}

$data = $_FILES["data"];
$templates = $_FILES["templates"];

// Handle PHP upload errors (post_max_size etc.)
if (($data["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  respond(false, "Upload data Excel gagal. Kode: " . ($data["error"] ?? "unknown"), [], [
    "debug" => [
      "php_upload_max_filesize" => ini_get("upload_max_filesize"),
      "php_post_max_size" => ini_get("post_max_size"),
    ]
  ]);
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
// READ EXCEL (PhpSpreadsheet)
// =========================
try {
  $spreadsheet = IOFactory::load($dataPath);
  $sheet = $spreadsheet->getActiveSheet();
  $rowsRaw = $sheet->toArray(null, true, true, true);

  // header mapping: expect at least "nama"
  // convert header row to lower keys
  $headerRow = $rowsRaw[1] ?? [];
  $map = [];
  foreach ($headerRow as $col => $val) {
    $k = strtolower(trim((string)$val));
    if ($k !== "") $map[$k] = $col;
  }
  if (!isset($map["nama"])) {
    throw new Exception("Excel wajib memiliki kolom header: nama");
  }

  $rows = [];
  for ($r = 2; $r <= count($rowsRaw); $r++) {
    $rr = $rowsRaw[$r] ?? [];
    $nama = trim((string)($rr[$map["nama"]] ?? ""));
    if ($nama === "") continue;

    $instansi = isset($map["instansi"]) ? trim((string)($rr[$map["instansi"]] ?? "")) : "";
    $nomor    = isset($map["nomor"])    ? trim((string)($rr[$map["nomor"]] ?? ""))    : "";

    $rows[] = [
      "nama" => $nama,
      "instansi" => $instansi,
      "nomor" => $nomor,
    ];
  }

  if (count($rows) === 0) {
    throw new Exception("Tidak ada data peserta. Pastikan kolom nama terisi.");
  }
} catch (Throwable $e) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);
  respond(false, "Gagal membaca Excel: " . $e->getMessage());
}

// =========================
// JOB OUTPUT DIR
// =========================
$jobOutDir = $outputRoot . DIRECTORY_SEPARATOR . "job_" . $stamp;
@mkdir($jobOutDir, 0777, true);

$logFile = $jobOutDir . DIRECTORY_SEPARATOR . "run.log";
file_put_contents($logFile, "STAMP: $stamp\nBASE: $base\n", FILE_APPEND);

// =========================
// PDF GENERATION (mPDF)
// =========================
function generate_pdfs_mpdf(string $base, array $templatesAbs, array $rows, string $outDirAbs): array {
  // A4 Landscape (mm)
  $pageW = 297.0;
  $pageH = 210.0;

  // Positions from generate.py (converted pt -> mm)
  $NOMOR_LEFT_MM   = pt_to_mm(74);
  $NOMOR_TOP_MM    = pt_to_mm(87);
  $NOMOR_BOTTOM_MM = pt_to_mm(32);
  $NOMOR_FONT_PT   = 12;
  $NOMOR_ANCHOR = "TOP_LEFT"; // TOP_LEFT / BOTTOM_LEFT

  $NAMA_Y_RATIO = 0.50;
  $NAMA_Y_OFFSET_MM = pt_to_mm(5);
  $NAMA_FONT_PT = 30;

  $INST_Y_RATIO = 0.44;
  $INST_Y_OFFSET_MM = pt_to_mm(0);
  $INST_FONT_PT = 14;

  // Fonts
  $fontNamaPath = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "fonts" . DIRECTORY_SEPARATOR . "Caudex-Regular.ttf";
  $fontInfoPath = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "fonts" . DIRECTORY_SEPARATOR . "Garet-Regular.ttf";
  if (!file_exists($fontNamaPath)) throw new Exception("Font nama tidak ditemukan: $fontNamaPath");
  if (!file_exists($fontInfoPath)) throw new Exception("Font info tidak ditemukan: $fontInfoPath");

  $tmpDir = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "tmp_mpdf";
  @mkdir($tmpDir, 0777, true);

  // mPDF requires fontDir include default
  $defaultFontDirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
  $defaultFontData = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];

  $fontDir = array_merge($defaultFontDirs, [dirname($fontNamaPath), dirname($fontInfoPath)]);

  $fontData = $defaultFontData + [
    "font_nama" => ["R" => basename($fontNamaPath)],
    "font_info" => ["R" => basename($fontInfoPath)],
  ];

  $generated = [];
  @mkdir($outDirAbs, 0777, true);

  foreach ($rows as $row) {
    $nama = trim((string)($row["nama"] ?? ""));
    if ($nama === "") continue;

    $instansi = trim((string)($row["instansi"] ?? ""));
    $nomor    = trim((string)($row["nomor"] ?? ""));

    $outPath = $outDirAbs . DIRECTORY_SEPARATOR . "SERTIFIKAT_" . sanitize_filename($nama) . ".pdf";

    $mpdf = new Mpdf([
      "mode" => "utf-8",
      "format" => [$pageW, $pageH],
      "orientation" => "L",
      "margin_left" => 0,
      "margin_right" => 0,
      "margin_top" => 0,
      "margin_bottom" => 0,
      "tempDir" => $tmpDir,
      "fontDir" => $fontDir,
      "fontdata" => $fontData,
      "default_font" => "font_info",
    ]);

    $mpdf->SetDisplayMode('fullpage');

    foreach ($templatesAbs as $idx => $tplAbs) {
      $mpdf->AddPageByArray([
        'orientation' => 'L',
        'margin-left' => 0,
        'margin-right' => 0,
        'margin-top' => 0,
        'margin-bottom' => 0,
      ]);

      // background full page
      $tplEsc = str_replace("'", "\\'", $tplAbs);
      $mpdf->WriteHTML("
        <div style='position:fixed; left:0; top:0; width:{$pageW}mm; height:{$pageH}mm; z-index:-1;'>
          <img src='{$tplEsc}' style='width:{$pageW}mm; height:{$pageH}mm;' />
        </div>
      ");

      // NOMOR all pages
      if ($nomor !== "") {
        $yNomor = ($NOMOR_ANCHOR === "BOTTOM_LEFT")
          ? ($pageH - $NOMOR_BOTTOM_MM)
          : $NOMOR_TOP_MM;

        $mpdf->WriteHTML("
          <div style='position:fixed; left:{$NOMOR_LEFT_MM}mm; top:{$yNomor}mm; font-family:font_info; font-size:{$NOMOR_FONT_PT}pt;'>
            Nomor: " . htmlspecialchars($nomor, ENT_QUOTES, 'UTF-8') . "
          </div>
        ");
      }

      // NAMA + INSTANSI only first template page
      if ($idx === 0) {
        // Ratio from bottom => convert to top for CSS
        $namaFromBottom = ($pageH * $NAMA_Y_RATIO) + $NAMA_Y_OFFSET_MM;
        $namaTop = $pageH - $namaFromBottom;

        $mpdf->WriteHTML("
          <div style='position:fixed; left:0; top:{$namaTop}mm; width:{$pageW}mm; text-align:center;
                      font-family:font_nama; font-size:{$NAMA_FONT_PT}pt;'>
            " . htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') . "
          </div>
        ");

        if ($instansi !== "") {
          $instFromBottom = ($pageH * $INST_Y_RATIO) + $INST_Y_OFFSET_MM;
          $instTop = $pageH - $instFromBottom;

          $mpdf->WriteHTML("
            <div style='position:fixed; left:0; top:{$instTop}mm; width:{$pageW}mm; text-align:center;
                        font-family:font_info; font-size:{$INST_FONT_PT}pt;'>
              " . htmlspecialchars($instansi, ENT_QUOTES, 'UTF-8') . "
            </div>
          ");
        }
      }
    }

    $mpdf->Output($outPath, Destination::FILE);
    $generated[] = $outPath;
  }

  return $generated;
}

try {
  $pdfs = generate_pdfs_mpdf($base, $templatePaths, $rows, $jobOutDir);
  if (!$pdfs) throw new Exception("Tidak ada PDF dihasilkan.");
} catch (Throwable $e) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);

  respond(false, "Gagal generate PDF: " . $e->getMessage(), [], [
    "debug" => [
      "base" => $base,
      "expected_autoload" => $autoload,
      "jobOutDir" => $jobOutDir,
      "logFile" => $logFile,
    ]
  ]);
}

// =========================
// COPY PDF TO PUBLIC DOWNLOAD
// =========================
$pdfLinks = [];
$pdfPublicAbs = [];

foreach ($pdfs as $p) {
  $cleanName = basename($p);
  $dest = $downloadDir . DIRECTORY_SEPARATOR . $cleanName;

  @copy($p, $dest);

  $pdfLinks[] = "/web/download/" . $cleanName;
  $pdfPublicAbs[] = $dest;
}

// =========================
// ZIP
// =========================
$zipName = "SERTIFIKAT_" . $stamp . ".zip";
$zipAbs  = $downloadDir . DIRECTORY_SEPARATOR . $zipName;
$zipLink = "/web/download/" . $zipName;

try {
  make_zip($zipAbs, $pdfPublicAbs);
} catch (Throwable $e) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);

  respond(false, "Gagal membuat ZIP: " . $e->getMessage(), [], [
    "debug" => [
      "base" => $base,
      "jobOutDir" => $jobOutDir,
      "downloadDir" => $downloadDir
    ]
  ]);
}

// cleanup uploads
@unlink($dataPath);
foreach ($templatePaths as $tp) @unlink($tp);

respond(true, "Selesai generate.", array_merge([$zipLink], $pdfLinks), [
  "debug" => [
    "base" => $base,
    "jobOutDir" => $jobOutDir,
    "downloadDir" => $downloadDir,
    "expected_autoload" => $autoload,
    "php_upload_max_filesize" => ini_get("upload_max_filesize"),
    "php_post_max_size" => ini_get("post_max_size"),
  ]
]);
