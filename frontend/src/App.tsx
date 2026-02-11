import { useMemo, useState } from "react";
import "./App.css";

const BASE_PATH = location.pathname.split("/").slice(0, 2).join("/"); 
// contoh: "/Sertif_Pelatihan"
const APACHE_BASE =
  location.hostname === "localhost"
    ? "http://localhost"
    : "";

const API_URL =
  location.hostname === "localhost"
    ? "http://localhost/Sertif_Pelatihan/web/run.php"
    : `${BASE_PATH}/web/run.php`;

type FileWithPreview = File & { __preview?: string };

function formatBytes(bytes: number) {
  const units = ["B", "KB", "MB", "GB"];
  let i = 0;
  let b = bytes;
  while (b >= 1024 && i < units.length - 1) {
    b /= 1024;
    i++;
  }
  return `${b.toFixed(b >= 10 || i === 0 ? 0 : 1)} ${units[i]}`;
}

export default function App() {
  const [templates, setTemplates] = useState<FileWithPreview[]>([]);
  const [data, setData] = useState<File | null>(null);

  const [dragT, setDragT] = useState(false);
  const [dragD, setDragD] = useState(false);

  const [loading, setLoading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [message, setMessage] = useState("");
  const [ok, setOk] = useState<boolean | null>(null);
  const [files, setFiles] = useState<string[]>([]);
  const [debug, setDebug] = useState<any>(null);

  const canSubmit = useMemo(
    () => templates.length > 0 && !!data && !loading,
    [templates, data, loading]
  );

  const totalTemplateSize = useMemo(
    () => templates.reduce((acc, f) => acc + f.size, 0),
    [templates]
  );

  const resetFeedback = () => {
    setMessage("");
    setFiles([]);
    setOk(null);
    setDebug(null);
  };

  const addTemplates = (incoming: File[]) => {
    const imgs = incoming.filter((f) => /\.(png|jpg|jpeg)$/i.test(f.name));
    if (imgs.length === 0) {
      setOk(false);
      setMessage("Template harus PNG/JPG.");
      return;
    }

    const withPrev: FileWithPreview[] = imgs.map((f) => {
      const fw = f as FileWithPreview;
      fw.__preview = URL.createObjectURL(f);
      return fw;
    });

    setTemplates((prev) => [...prev, ...withPrev]);
  };

  const onPickTemplates = (e: React.ChangeEvent<HTMLInputElement>) => {
    resetFeedback();
    const picked = Array.from(e.target.files || []);
    if (!picked.length) return;
    addTemplates(picked);
    e.target.value = "";
  };

  const onPickData = (e: React.ChangeEvent<HTMLInputElement>) => {
    resetFeedback();
    const f = e.target.files?.[0];
    if (!f) return;
    if (!/\.xlsx$/i.test(f.name)) {
      setOk(false);
      setMessage("Data harus file Excel .xlsx");
      return;
    }
    setData(f);
    e.target.value = "";
  };

  const onDropTemplates = (e: React.DragEvent) => {
    e.preventDefault();
    setDragT(false);
    resetFeedback();
    const dropped = Array.from(e.dataTransfer.files || []);
    addTemplates(dropped);
  };

  const onDropData = (e: React.DragEvent) => {
    e.preventDefault();
    setDragD(false);
    resetFeedback();
    const f = e.dataTransfer.files?.[0];
    if (!f) return;
    if (!/\.xlsx$/i.test(f.name)) {
      setOk(false);
      setMessage("Data harus file Excel .xlsx");
      return;
    }
    setData(f);
  };

  const removeTemplateAt = (idx: number) => {
    setTemplates((prev) => {
      const next = [...prev];
      const removed = next.splice(idx, 1)[0];
      if (removed?.__preview) URL.revokeObjectURL(removed.__preview);
      return next;
    });
  };

  const clearTemplates = () => {
    templates.forEach((t) => t.__preview && URL.revokeObjectURL(t.__preview));
    setTemplates([]);
  };

  const submit = async () => {
    if (templates.length === 0 || !data) return;

    setLoading(true);
    setProgress(8);
    resetFeedback();

    const timer = setInterval(() => {
      setProgress((p) => (p < 92 ? p + Math.max(1, Math.round((92 - p) / 10)) : p));
    }, 220);

    try {
      const form = new FormData();
      templates.forEach((f) => form.append("templates[]", f));
      form.append("data", data);

      const res = await fetch(API_URL, { method: "POST", body: form });

      const text = await res.text();
      let json: any;

      try {
        json = JSON.parse(text);
      } catch {
        throw new Error(
          `Server tidak mengembalikan JSON. HTTP ${res.status}\nBody (potongan):\n${text.slice(0, 400)}`
        );
      }

      setOk(!!json.ok);
      setMessage(json.message || (json.ok ? "Sukses." : "Gagal."));
      setFiles(Array.isArray(json.files) ? json.files : []);
      setDebug(json.debug ?? null);

      setProgress(100);
    } catch (e: any) {
      setOk(false);
      setMessage(e?.message || "Gagal memproses.");
      setProgress(100);
    } finally {
      clearInterval(timer);
      setTimeout(() => setLoading(false), 200);
      setTimeout(() => setProgress(0), 900);
    }
  };

  const step1Done = templates.length > 0;
  const step2Done = !!data;
  const step3Done = ok === true && files.length > 0;

  return (
    <div className="page">
      <div className="shell">
        <header className="header">
          <div>
            <div className="kicker">Tool</div>
            <h1 className="title">PDF Generator</h1>
            <p className="subtitle">
              Upload <b>template (PNG/JPG)</b> & <b>data peserta (.xlsx)</b>, lalu generate PDF otomatis.
            </p>
          </div>
          <div className="env">
            <span className="pill">{location.hostname === "localhost" ? "LOCAL" : "PROD"}</span>
          </div>
        </header>

        <div className="steps">
          <div className={`step ${step1Done ? "done" : ""}`}>
            <div className="dot">1</div>
            <div>
              <div className="stepTitle">Template</div>
              <div className="stepDesc">Tambah 1+ gambar (per halaman)</div>
            </div>
          </div>
          <div className={`step ${step2Done ? "done" : ""}`}>
            <div className="dot">2</div>
            <div>
              <div className="stepTitle">Data Excel</div>
              <div className="stepDesc">Upload file .xlsx</div>
            </div>
          </div>
          <div className={`step ${step3Done ? "done" : ""}`}>
            <div className="dot">3</div>
            <div>
              <div className="stepTitle">Download</div>
              <div className="stepDesc">Ambil hasil PDF</div>
            </div>
          </div>
        </div>

        <div className="grid">
          {/* TEMPLATE */}
          <section
            className={`dropzone ${templates.length ? "has" : ""} ${dragT ? "drag" : ""}`}
            onDragOver={(e) => {
              e.preventDefault();
              setDragT(true);
            }}
            onDragLeave={() => setDragT(false)}
            onDrop={onDropTemplates}
          >
            <div className="zoneHead">
              <div>
                <div className="zoneTitle">Template (PNG/JPG) — Multi halaman</div>
                <div className="zoneMeta">
                  {templates.length ? (
                    <>
                      <span className="badge soft">Halaman: {templates.length}</span>
                      <span className="badge soft">Total: {formatBytes(totalTemplateSize)}</span>
                    </>
                  ) : (
                    <span className="muted">Tips: drag & drop</span>
                  )}
                </div>
              </div>

              <div className="zoneActions">
                {templates.length > 0 && (
                  <button className="btnGhost" type="button" onClick={clearTemplates}>
                    Hapus semua
                  </button>
                )}
                <label className="btnSmall">
                  Pilih file
                  <input className="hiddenInput" type="file" accept=".png,.jpg,.jpeg" multiple onChange={onPickTemplates} />
                </label>
              </div>
            </div>

            {!templates.length ? (
              <div className="empty">
                <div className="emptyIcon">🖼️</div>
                <div className="emptyText">
                  <div className="emptyTitle">Drop template di sini</div>
                  <div className="emptySub">1 template = 1 halaman</div>
                </div>
              </div>
            ) : (
              <div className="thumbGrid">
                {templates.map((f, i) => (
                  <div className="thumb" key={f.name + i}>
                    <div className="thumbImg">
                      {f.__preview ? <img src={f.__preview} alt={f.name} /> : null}
                      <div className="thumbIndex">Hal {i + 1}</div>
                    </div>

                    <div className="thumbInfo">
                      <div className="thumbName" title={f.name}>
                        {f.name}
                      </div>
                      <div className="thumbMeta">{formatBytes(f.size)}</div>
                    </div>

                    <button className="thumbRemove" type="button" onClick={() => removeTemplateAt(i)} aria-label="hapus">
                      ×
                    </button>
                  </div>
                ))}
              </div>
            )}
          </section>

          {/* DATA */}
          <section
            className={`dropzone ${data ? "has" : ""} ${dragD ? "drag" : ""}`}
            onDragOver={(e) => {
              e.preventDefault();
              setDragD(true);
            }}
            onDragLeave={() => setDragD(false)}
            onDrop={onDropData}
          >
            <div className="zoneHead">
              <div>
                <div className="zoneTitle">Data Peserta (Excel .xlsx)</div>
                <div className="zoneMeta">
                  {data ? (
                    <>
                      <span className="badge soft">{data.name}</span>
                      <span className="badge soft">{formatBytes(data.size)}</span>
                    </>
                  ) : (
                    <span className="muted">Pastikan format .xlsx</span>
                  )}
                </div>
              </div>

              <div className="zoneActions">
                {data && (
                  <button className="btnGhost" type="button" onClick={() => setData(null)}>
                    Hapus
                  </button>
                )}
                <label className="btnSmall">
                  Pilih file
                  <input className="hiddenInput" type="file" accept=".xlsx" onChange={onPickData} />
                </label>
              </div>
            </div>

            {!data ? (
              <div className="empty">
                <div className="emptyIcon">📄</div>
                <div className="emptyText">
                  <div className="emptyTitle">Drop file Excel di sini</div>
                  <div className="emptySub">format .xlsx</div>
                </div>
              </div>
            ) : (
              <div className="fileCard">
                <div className="fileIcon">XLSX</div>
                <div className="fileText">
                  <div className="fileName">{data.name}</div>
                  <div className="fileSub">{formatBytes(data.size)}</div>
                </div>
              </div>
            )}
          </section>
        </div>

        {/* ACTION */}
        <div className="actionBar">
          <button className="btnPrimary" disabled={!canSubmit} onClick={submit}>
            {loading ? (
              <span className="btnRow">
                <span className="spinner" /> Memproses…
              </span>
            ) : (
              "Generate PDF"
            )}
          </button>

          <div className="actionHint">
            {templates.length === 0 && <span>Tambah template dulu.</span>}
            {templates.length > 0 && !data && <span>Upload Excel peserta.</span>}
            {templates.length > 0 && data && !loading && <span>Siap generate ✅</span>}
          </div>
        </div>

        {loading && (
          <div className="progressWrap" aria-label="progress">
            <div className="progressBar" style={{ width: `${progress}%` }} />
          </div>
        )}

        {message && <div className={`toast ${ok ? "ok" : "err"}`}>{message}</div>}

        {/* DEBUG BOX */}
        {debug && (
          <div style={{ marginTop: 14 }}>
            <div style={{ fontWeight: 700, marginBottom: 8 }}>Debug</div>
            <pre
              style={{
                whiteSpace: "pre-wrap",
                background: "#111",
                color: "#0f0",
                padding: 12,
                borderRadius: 12,
                fontSize: 12,
                lineHeight: 1.35,
              }}
            >
              {JSON.stringify(debug, null, 2)}
            </pre>
          </div>
        )}

        {files.length > 0 && (
          <section className="resultCard">
            <div className="resultHead">
              <h3>Hasil Download</h3>
              <span className="badge">{files.length} file</span>
            </div>

            <ul className="resultList">
              {files.map((f) => {
                const name = f.split("/").pop();
                return (
                  <li key={f} className="resultItem">
                    <div className="resultLeft">
                      <div className="pdfIcon">PDF</div>
                      <div className="resultName">{name}</div>
                    </div>

                    <a className="btnLink" href={APACHE_BASE + f} target="_blank" rel="noreferrer">
                      Download
                    </a>
                  </li>
                );
              })}
            </ul>
          </section>
        )}
      </div>
    </div>
  );
}
