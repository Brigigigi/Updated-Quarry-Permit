import sys, zipfile, re

def extract_text(path: str) -> str:
    with zipfile.ZipFile(path, 'r') as z:
        with z.open('word/document.xml') as f:
            xml = f.read().decode('utf-8', errors='ignore')
    text = re.sub(r'<[^>]+>', ' ', xml)
    text = re.sub(r'\s+', ' ', text).strip()
    return text

if __name__ == '__main__':
    out = sys.stdout.buffer
    for p in sys.argv[1:]:
        try:
            txt = extract_text(p)
            out.write((f"--- {p} ---\n{txt[:8000]}\n").encode('utf-8', 'replace'))
        except Exception as e:
            out.write((f"ERROR reading {p}: {e}\n").encode('utf-8', 'replace'))
