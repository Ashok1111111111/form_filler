#!/usr/bin/env python3
import sys, os
# Allow pylibs path to be set via PYTHONPATH env or add it here
script_dir = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(script_dir, 'pylibs'))
import json, base64
import pymupdf as fitz

pdf_path = sys.argv[1]
max_pages = int(sys.argv[2]) if len(sys.argv) > 2 else 3

try:
    doc = fitz.open(pdf_path)
    pages = []
    for i in range(min(len(doc), max_pages)):
        page = doc[i]
        # Render at 2x (144 DPI) for good OCR quality, cap at 1800px
        mat = fitz.Matrix(2, 2)
        pix = page.get_pixmap(matrix=mat)
        # Scale down if too large
        if max(pix.width, pix.height) > 1800:
            scale = 1800 / max(pix.width, pix.height)
            mat = fitz.Matrix(2 * scale, 2 * scale)
            pix = page.get_pixmap(matrix=mat)
        img_bytes = pix.tobytes("jpeg", jpg_quality=85)
        b64 = base64.b64encode(img_bytes).decode()
        pages.append({
            "base64": f"data:image/jpeg;base64,{b64}",
            "label": f"Page {i+1}/{min(len(doc), max_pages)}"
        })
    doc.close()
    print(json.dumps({"success": True, "pages": pages}))
except Exception as e:
    print(json.dumps({"success": False, "error": str(e)}))
