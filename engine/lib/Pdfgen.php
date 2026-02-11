<?php
// engine/lib/PdfGen.php
require_once __DIR__ . "/../../web/vendor/autoload.php";

use TCPDF;

class PdfGen
{
    public static function sanitizeFilename(string $s): string
    {
        $keep = " _-().,";
        $out = "";
        $s = trim($s);
        $len = strlen($s);
        for ($i=0; $i<$len; $i++) {
            $ch = $s[$i];
            if (ctype_alnum($ch) || str_contains($keep, $ch)) $out .= $ch;
        }
        $out = trim($out);
        return $out !== "" ? $out : "NONAME";
    }

    // posisi mirip code python Mbak (landscape A4)
    public static function generateMulti(
        array $templatesAbs,      // array of file paths
        array $rows,              // [ [nama, instansi, nomor], ... ]
        string $outDirAbs,
        array $cfg = []
    ): array {
        @mkdir($outDirAbs, 0777, true);

        // default config (bisa Mbak ubah)
        $cfg = array_merge([
            // NOMOR
            "nomor_anchor" => "TOP_LEFT", // TOP_LEFT/BOTTOM_LEFT
            "nomor_left" => 74,
            "nomor_top" => 87,
            "nomor_bottom" => 32,
            "nomor_font_size" => 12,

            // NAMA
            "nama_y_ratio" => 0.50,
            "nama_y_offset" => 5,
            "nama_font_size" => 30,

            // INSTANSI
            "instansi_y_ratio" => 0.44,
            "instansi_y_offset" => 0,
            "instansi_font_size" => 14,

            // Fonts (TTF)
            "font_nama" => __DIR__ . "/../assets/fonts/Caudex-Regular.ttf",
            "font_info" => __DIR__ . "/../assets/fonts/Garet-Regular.ttf",
        ], $cfg);

        // register fonts in TCPDF
        $fontNama = TCPDF_FONTS::addTTFfont($cfg["font_nama"], "TrueTypeUnicode", "", 32);
        $fontInfo = TCPDF_FONTS::addTTFfont($cfg["font_info"], "TrueTypeUnicode", "", 32);

        if (!$fontNama || !$fontInfo) {
            throw new Exception("Gagal load font TTF. Pastikan file font ada & readable.");
        }

        $generated = [];

        foreach ($rows as $row) {
            $nama = trim((string)($row["nama"] ?? ""));
            if ($nama === "") continue;

            $instansi = trim((string)($row["instansi"] ?? ""));
            $nomor = trim((string)($row["nomor"] ?? ""));

            $outPath = $outDirAbs . DIRECTORY_SEPARATOR . "SERTIFIKAT_" . self::sanitizeFilename($nama) . ".pdf";

            // A4 Landscape
            $pdf = new TCPDF("L", "pt", "A4", true, "UTF-8", false);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(0, 0, 0, true);
            $pdf->SetAutoPageBreak(false, 0);

            // ukuran halaman
            $pageW = 842; // A4 landscape in pt (approx)
            $pageH = 595;

            foreach ($templatesAbs as $idx => $tpl) {
                $pdf->AddPage("L", "A4");

                // background image full page
                $pdf->Image($tpl, 0, 0, $pageW, $pageH, "", "", "", false, 300, "", false, false, 0);

                // NOMOR (tampil semua halaman)
                if ($nomor !== "") {
                    $x = (float)$cfg["nomor_left"];
                    if ($cfg["nomor_anchor"] === "BOTTOM_LEFT") {
                        $y = $pageH - (float)$cfg["nomor_bottom"];
                    } else {
                        $y = (float)$cfg["nomor_top"];
                    }

                    $pdf->SetFont($fontInfo, "", (float)$cfg["nomor_font_size"]);
                    // TCPDF koordinat y dari atas, jadi pakai SetXY langsung
                    $pdf->SetXY($x, $y);
                    $pdf->Cell(0, 0, "Nomor: " . $nomor, 0, 1, "L", false);
                }

                // NAMA + INSTANSI (hanya halaman 1)
                if ($idx === 0) {
                    $centerX = $pageW / 2;

                    // y dari atas: y_ratio * pageH (python ratio dari bawah)
                    // python: nama_y = H*ratio + offset (dari bawah)
                    // TCPDF: dari atas => convert: topY = pageH - (H*ratio + offset)
                    $namaFromBottom = ($pageH * (float)$cfg["nama_y_ratio"]) + (float)$cfg["nama_y_offset"];
                    $namaY = $pageH - $namaFromBottom;

                    $pdf->SetFont($fontNama, "", (float)$cfg["nama_font_size"]);
                    $pdf->SetXY(0, $namaY);
                    $pdf->Cell($pageW, 0, $nama, 0, 1, "C", false);

                    if ($instansi !== "") {
                        $instFromBottom = ($pageH * (float)$cfg["instansi_y_ratio"]) + (float)$cfg["instansi_y_offset"];
                        $instY = $pageH - $instFromBottom;

                        $pdf->SetFont($fontInfo, "", (float)$cfg["instansi_font_size"]);
                        $pdf->SetXY(0, $instY);
                        $pdf->Cell($pageW, 0, $instansi, 0, 1, "C", false);
                    }
                }
            }

            $pdf->Output($outPath, "F");
            $generated[] = $outPath;
        }

        return $generated;
    }
}
