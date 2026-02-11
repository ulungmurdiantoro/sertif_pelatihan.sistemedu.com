<?php
/**
 * web/run.php (FULL) - mPDF + PhpSpreadsheet (TANPA PYTHON)
 * Vendor autoload: engine/vendor/autoload.php
 */

declare(strict_types=1);

$DEBUG = true;

ob_start();
header('Content-Type: application/json; charset=utf-8');

if ($DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', '0');
  error_reporting(E_ALL);
}

set_exception_handler(function(Throwable $e) {
  $garbage = ob_get_clean();
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "EXCEPTION: " . $e->getMessage(),
    "files" => [],
    "debug" => [
      "type" => get_class($e),
      "file" => $e->getFile(),
      "line" => $e->getLine(),
      "trace" => array_slice(explode("\n", $e->getTraceAsString()), 0, 18),
      "garbage" => $garbage,
    ]
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
});

register_shutdown_function(function() {
  $err = error_get_last();
  if (!$err) return;

  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
  if (!in_array($err["type"], $fatalTypes, true)) return;

  $garbage = ob_get_clean();
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "FATAL: " . ($err["message"] ?? "Unknown fatal error"),
    "files" => [],
    "debug" => [
      "file" => $err["file"] ?? null,
      "line" => $err["line"] ?? null,
      "type" => $err["type"] ?? null,
      "garbage" => $garbage,
    ]
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
});

// =========================
// CORS (LOCAL)
// =========================
$allowed = ["http://localhost:5173"];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if (($_SERVER["REQUEST_METHOD"] ?? '') === "OPTIONS") {
  http_response_code(204);
  ob_end_clean();
  exit;
}

// =========================
// HELPERS
// =========================
function respond(bool $ok, string $message, array $files = [], array $extra = []): void {
  $garbage = ob_get_clean();
  if ($garbage) $extra["debug_garbage"] = $garbage;

  echo json_encode(array_merge([
    "ok" => $ok,
    "message" => $message,
    "files" => $files,
  ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
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

function sanitize_filename(string $s): string {
  $keep = " _-().,";
  $s = trim($s);
  $out = "";
  $len = strlen($s);
  for ($i=0; $i<$len; $i++) {
    $ch = $s[$i];
    if (ctype_alnum($ch) || str_contains($keep, $ch)) $out .= $ch;
  }
  $out = trim($out);
  return $out !== "" ? $out : "NONAME";
}

function pt_to_mm(float $pt): float {
  return $pt * 0.3527777778;
}

// =========================
// METHOD GUARD
// =========================
if (($_SERVER["REQUEST_METHOD"] ?? '') !== "POST") {
  respond(false, "Gunakan POST.");
}

// =========================
// BASE PATHS
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
// AUTOLOAD (engine/vendor)
// =========================
$AUTOLOAD = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";
if (!file_exists($AUTOLOAD)) {
  respond(false, "autoload.php tidak ditemukan di engine/vendor. Jalankan composer install di folder engine.", [], [
    "debug" => ["expected_autoload" => $AUTOLOAD]
  ]);
}
require_once $AUTOLOAD;

if (!class_exists("\\PhpOffice\\PhpSpreadsheet\\IOFactory")) {
  respond(false, "PhpSpreadsheet tidak tersedia. Pastikan phpoffice/phpspreadsheet terinstall.");
}
if (!class_exists("\\Mpdf\\Mpdf")) {
  respond(false, "mPDF tidak tersedia. Pastikan mpdf/mpdf terinstall.");
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use Mpdf\Mpdf;

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
// READ EXCEL
// =========================
$spreadsheet = IOFactory::load($dataPath);
$sheet = $spreadsheet->getActiveSheet();
$rowsRaw = $sheet->toArray(null, true, true, true);

$headersRow = $rowsRaw[1] ?? null;
if (!$headersRow) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);
  respond(false, "Excel kosong / tidak ada header di baris 1.");
}

$colMap = [];
foreach ($headersRow as $col => $val) {
  $name = strtolower(trim((string)$val));
  if ($name !== "") $colMap[$name] = $col;
}
if (!isset($colMap["nama"])) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);
  respond(false, "Excel wajib punya header kolom: nama (baris 1).");
}

$rows = [];
for ($i = 2; $i <= count($rowsRaw); $i++) {
  $r = $rowsRaw[$i] ?? null;
  if (!$r) continue;

  $nama = trim((string)($r[$colMap["nama"]] ?? ""));
  if ($nama === "") continue;

  $instansi = isset($colMap["instansi"]) ? trim((string)($r[$colMap["instansi"]] ?? "")) : "";
  $nomor    = isset($colMap["nomor"]) ? trim((string)($r[$colMap["nomor"]] ?? "")) : "";

  $rows[] = ["nama" => $nama, "instansi" => $instansi, "nomor" => $nomor];
}

if (!$rows) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);
  respond(false, "Tidak ada data peserta terbaca. Pastikan kolom 'nama' terisi mulai baris 2.");
}

// =========================
// PDF GENERATOR (mPDF) - FIX strict font access
// =========================
function generate_pdfs_mpdf(string $base, array $templatesAbs, array $rows, string $outDirAbs): array {
  // Base size A4 PORTRAIT (mm) — biar mPDF tidak double-rotate
  $baseW = 210.0;
  $baseH = 297.0;

  // Real page size setelah landscape (mm)
  $landW = 297.0;
  $landH = 210.0;

  // posisi (dari generate.py) -> hasil mm
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

  // fonts
  $fontNamaPath = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "fonts" . DIRECTORY_SEPARATOR . "Caudex-Regular.ttf";
  $fontInfoPath = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "fonts" . DIRECTORY_SEPARATOR . "Garet-Regular.ttf";
  if (!file_exists($fontNamaPath)) throw new Exception("Font nama tidak ditemukan: $fontNamaPath");
  if (!file_exists($fontInfoPath)) throw new Exception("Font info tidak ditemukan: $fontInfoPath");

  $tmpDir = $base . DIRECTORY_SEPARATOR . "engine" . DIRECTORY_SEPARATOR . "tmp_mpdf";
  @mkdir($tmpDir, 0777, true);

  $customFontDir  = dirname($fontNamaPath);
  $customFontDir2 = dirname($fontInfoPath);

  $generated = [];
  @mkdir($outDirAbs, 0777, true);

  foreach ($rows as $row) {
    $nama = trim((string)($row["nama"] ?? ""));
    if ($nama === "") continue;

    $instansi = trim((string)($row["instansi"] ?? ""));
    $nomor    = trim((string)($row["nomor"] ?? ""));

    $outPath = $outDirAbs . DIRECTORY_SEPARATOR . "SERTIFIKAT_" . sanitize_filename($nama) . ".pdf";

    // ✅ format = PORTRAIT, landscape dipaksa via AddPageByArray
    $mpdf = new \Mpdf\Mpdf([
      "mode" => "utf-8",
      "format" => [$baseW, $baseH],   // <-- penting
      "orientation" => "P",           // <-- penting
      "margin_left" => 0,
      "margin_right" => 0,
      "margin_top" => 0,
      "margin_bottom" => 0,
      "tempDir" => $tmpDir,

      "fontDir" => array_values(array_unique([$customFontDir, $customFontDir2])),
      "fontdata" => [
        "font_nama" => ["R" => basename($fontNamaPath)],
        "font_info" => ["R" => basename($fontInfoPath)],
      ],
      "default_font" => "font_info",
    ]);

    // hapus halaman default
    $mpdf->DeletePages(1);

    $mpdf->SetDefaultBodyCSS("margin", "0");
    $mpdf->SetDefaultBodyCSS("padding", "0");

    foreach ($templatesAbs as $idx => $tplAbs) {
      // ✅ paksa LANDSCAPE per halaman
      $mpdf->AddPageByArray([
        "orientation"   => "L",
        "sheet-size"    => [$baseW, $baseH], // base portrait, orientasi L yang memutar
        "margin-left"   => 0,
        "margin-right"  => 0,
        "margin-top"    => 0,
        "margin-bottom" => 0,
      ]);

      // background full page (pakai ukuran LANDSCAPE NYATA)
      $mpdf->WriteHTML("
        <div style='position:fixed; left:0; top:0; width:{$landW}mm; height:{$landH}mm; z-index:-1;'>
          <img src='{$tplAbs}' style='width:{$landW}mm; height:{$landH}mm;' />
        </div>
      ");

      // NOMOR semua halaman (koordinat top dihitung di landscape)
      if ($nomor !== "") {
        $NOMOR_UP_MM = 4.0; // ubah sesuai kebutuhan (5-15mm)
        $yNomor = ($NOMOR_ANCHOR === "BOTTOM_LEFT")
          ? ($landH - $NOMOR_BOTTOM_MM)
          : max(0, $NOMOR_TOP_MM - $NOMOR_UP_MM);


        $mpdf->WriteHTML("
          <div style='position:fixed; left:{$NOMOR_LEFT_MM}mm; top:{$yNomor}mm; font-family:font_info; font-size:{$NOMOR_FONT_PT}pt;'>
            Nomor: " . htmlspecialchars($nomor, ENT_QUOTES, 'UTF-8') . "
          </div>
        ");
      }

      // NAMA + INSTANSI hanya halaman 1 (pakai tinggi landscape)
      if ($idx === 0) {
        $NAMA_UP_MM = 12.0; // ubah sesuai kebutuhan (3-12mm)
        $namaFromBottom = ($landH * $NAMA_Y_RATIO) + $NAMA_Y_OFFSET_MM;
        $namaTop = max(0, ($landH - $namaFromBottom) - $NAMA_UP_MM);

        $mpdf->WriteHTML("
          <div style='position:fixed; left:0; top:{$namaTop}mm; width:{$landW}mm; text-align:center;
                      font-family:font_nama; font-size:{$NAMA_FONT_PT}pt;'>
            " . htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') . "
          </div>
        ");

        if ($instansi !== "") {
          $instFromBottom = ($landH * $INST_Y_RATIO) + $INST_Y_OFFSET_MM;
          $instTop = $landH - $instFromBottom;

          $mpdf->WriteHTML("
            <div style='position:fixed; left:0; top:{$instTop}mm; width:{$landW}mm; text-align:center;
                        font-family:font_info; font-size:{$INST_FONT_PT}pt;'>
              " . htmlspecialchars($instansi, ENT_QUOTES, 'UTF-8') . "
            </div>
          ");
        }
      }
    }

    $mpdf->Output($outPath, \Mpdf\Output\Destination::FILE);
    $generated[] = $outPath;
  }

  return $generated;
}


// =========================
// RUN GENERATE
// =========================
$jobOutDir = $outputRoot . DIRECTORY_SEPARATOR . "job_" . $stamp;
@mkdir($jobOutDir, 0777, true);

$pdfs = generate_pdfs_mpdf($base, $templatePaths, $rows, $jobOutDir);

if (!$pdfs) {
  @unlink($dataPath);
  foreach ($templatePaths as $tp) @unlink($tp);
  respond(false, "Tidak ada PDF dihasilkan.");
}

// =========================
// COPY PDF to public + ZIP
// =========================
$projectName = basename($base);
$baseUrl = "/" . $projectName;

$pdfLinks = [];
$pdfPublicAbs = [];

foreach ($pdfs as $absPdf) {
  $name = basename($absPdf);
  $dest = $downloadDir . DIRECTORY_SEPARATOR . $name;
  @copy($absPdf, $dest);

  $pdfLinks[] = $baseUrl . "/web/download/" . $name;
  $pdfPublicAbs[] = $dest;
}

$zipName = "SERTIFIKAT_" . $stamp . ".zip";
$zipAbs  = $downloadDir . DIRECTORY_SEPARATOR . $zipName;
$zipLink = $baseUrl . "/web/download/" . $zipName;

make_zip($zipAbs, $pdfPublicAbs);

// cleanup uploads
@unlink($dataPath);
foreach ($templatePaths as $tp) @unlink($tp);

respond(true, "Selesai generate.", array_merge([$zipLink], $pdfLinks), [
  "debug" => [
    "project" => $projectName,
    "pdfCount" => count($pdfs),
    "zip" => $zipName,
  ]
]);
