import os
import argparse
import pandas as pd

from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import A4, landscape
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont


# =========================
# POSITION CONFIG (UBAH DI SINI)
# Koordinat ReportLab: (0,0) = pojok kiri bawah
# =========================

# --- NOMOR ---
NOMOR_ANCHOR = "TOP_LEFT"   # "TOP_LEFT" atau "BOTTOM_LEFT"
NOMOR_MARGIN_LEFT = 74
NOMOR_MARGIN_TOP = 87
NOMOR_MARGIN_BOTTOM = 32
NOMOR_FONT_SIZE = 12

# --- NAMA ---
NAMA_CENTER_X = None        # None = otomatis W/2
NAMA_Y_RATIO = 0.50         # 0..1 (tinggi halaman)
NAMA_Y_OFFSET = 5           # px (+ naik, - turun)
NAMA_FONT_SIZE = 30

# --- INSTANSI (opsional) ---
INSTANSI_Y_RATIO = 0.44
INSTANSI_Y_OFFSET = 0
INSTANSI_FONT_SIZE = 14


# =========================
# FONT FILES (UBAH DI SINI kalau nama file font beda)
# =========================
FONT_NAMA_FILE = "Caudex-Regular.ttf"
FONT_INFO_FILE = "Garet-Regular.ttf"


def sanitize_filename(s: str) -> str:
    keep = " _-().,"
    return "".join(ch for ch in s if ch.isalnum() or ch in keep).strip() or "NONAME"


def clean_cell(v) -> str:
    if v is None:
        return ""
    try:
        if pd.isna(v):
            return ""
    except Exception:
        pass
    return str(v).strip()


def register_fonts():
    base_dir = os.path.dirname(os.path.abspath(__file__))  # engine/
    fonts_dir = os.path.join(base_dir, "assets", "fonts")

    name_font_path = os.path.join(fonts_dir, FONT_NAMA_FILE)
    info_font_path = os.path.join(fonts_dir, FONT_INFO_FILE)

    if not os.path.isfile(name_font_path):
        raise FileNotFoundError(f"Font NAMA tidak ditemukan: {name_font_path}")
    if not os.path.isfile(info_font_path):
        raise FileNotFoundError(f"Font INFO (nomor/instansi) tidak ditemukan: {info_font_path}")

    pdfmetrics.registerFont(TTFont("FONT_NAMA", name_font_path))  # Caudex
    pdfmetrics.registerFont(TTFont("FONT_INFO", info_font_path))  # Garet


def calc_nomor_pos(H: float):
    x = NOMOR_MARGIN_LEFT
    if NOMOR_ANCHOR == "TOP_LEFT":
        y = H - NOMOR_MARGIN_TOP
    elif NOMOR_ANCHOR == "BOTTOM_LEFT":
        y = NOMOR_MARGIN_BOTTOM
    else:
        y = H - NOMOR_MARGIN_TOP
    return x, y


def draw_page(
    c: canvas.Canvas,
    W: float,
    H: float,
    template_path: str,
    nama: str,
    instansi: str = "",
    nomor: str = "",
    show_nama: bool = True,   # ⬅️ TAMBAHAN
):
    # background
    c.drawImage(template_path, 0, 0, width=W, height=H, mask="auto")

    # =========================
    # NOMOR (tetap tampil di semua halaman)
    # =========================
    if nomor:
        x, y = calc_nomor_pos(H)
        c.setFont("FONT_INFO", NOMOR_FONT_SIZE)
        c.drawString(x, y, f"Nomor: {nomor}")

    # =========================
    # NAMA (HANYA HALAMAN 1)
    # =========================
    if show_nama:
        center_x = (W / 2) if (NAMA_CENTER_X is None) else NAMA_CENTER_X
        nama_y = (H * NAMA_Y_RATIO) + NAMA_Y_OFFSET
        c.setFont("FONT_NAMA", NAMA_FONT_SIZE)
        c.drawCentredString(center_x, nama_y, nama)

        # INSTANSI ikut nama (opsional)
        if instansi:
            inst_y = (H * INSTANSI_Y_RATIO) + INSTANSI_Y_OFFSET
            c.setFont("FONT_INFO", INSTANSI_FONT_SIZE)
            c.drawCentredString(center_x, inst_y, instansi)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--templates", required=True, help="Template paths dipisah karakter |")
    ap.add_argument("--data", required=True, help="Excel .xlsx minimal kolom nama")
    ap.add_argument("--outdir", required=True, help="Output folder PDFs")
    args = ap.parse_args()

    os.makedirs(args.outdir, exist_ok=True)
    register_fonts()

    templates = [p.strip() for p in (args.templates or "").split("|") if p.strip()]
    if len(templates) < 1:
        raise ValueError("Minimal 1 template harus dikirim lewat --templates")

    for t in templates:
        if not os.path.isfile(t):
            raise FileNotFoundError(f"Template tidak ditemukan: {t}")

    df = pd.read_excel(args.data)
    if "nama" not in df.columns:
        raise ValueError("Excel wajib memiliki kolom: nama")

    W, H = landscape(A4)

    total = 0
    for _, row in df.iterrows():
        nama = clean_cell(row.get("nama"))
        if not nama:
            continue

        instansi = clean_cell(row.get("instansi")) if "instansi" in df.columns else ""
        nomor = clean_cell(row.get("nomor")) if "nomor" in df.columns else ""

        out_path = os.path.join(args.outdir, f"SERTIFIKAT_{sanitize_filename(nama)}.pdf")
        c = canvas.Canvas(out_path, pagesize=(W, H))

        for page_index, tpl in enumerate(templates):
            draw_page(
                c,
                W,
                H,
                tpl,
                nama,
                instansi,
                nomor,
                show_nama=(page_index == 0)  # ⬅️ HALAMAN 1 SAJA
            )
            c.showPage()

        c.save()
        total += 1

    print(f"SUKSES generate {total} PDF ({len(templates)} halaman) ke: {args.outdir}")


if __name__ == "__main__":
    main()
