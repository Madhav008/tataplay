from flask import Flask, Response, jsonify
import requests
import re
import xml.etree.ElementTree as ET
import base64

app = Flask(__name__)

MPD_URL = "https://tataplay.ipl2025.space/tataplay/manifest.mpd?id=40"
LICENSE_URL = "https://tp.drmlive-01.workers.dev/?id=40"

def hex_kid_to_base64url(hex_kid):
    kid_bytes = bytes.fromhex(hex_kid.replace('-', ''))
    b64url_kid = base64.urlsafe_b64encode(kid_bytes).rstrip(b'=').decode('utf-8')
    return b64url_kid

def inject_namespace_if_missing(xml_text):
    if 'xmlns:cenc' not in xml_text:
        xml_text = re.sub(r'(<MPD[^>]*?)>', r'\1 xmlns:cenc="urn:mpeg:cenc:2013">', xml_text)
    return xml_text

def strip_ns(xml):
    return ET.tostring(ET.fromstring(xml)).decode().replace('ns0:', '').replace(':ns0', '')

def extract_default_kid(mpd_xml):
    ns = {
        'mpd': 'urn:mpeg:dash:schema:mpd:2011',
        'cenc': 'urn:mpeg:cenc:2013'
    }
    root = ET.fromstring(mpd_xml)
    for cp in root.findall(".//mpd:ContentProtection", ns):
        scheme_id = cp.attrib.get("schemeIdUri", "")
        if scheme_id == "urn:mpeg:dash:mp4protection:2011":
            default_kid = cp.attrib.get("{urn:mpeg:cenc:2013}default_KID")
            if default_kid:
                return default_kid
    return None

def send_kid_to_license_server(kid):
    headers = {
        "Accept-Encoding": "gzip",
        "Connection": "Keep-Alive",
        "Content-Type": "application/json",
        "Origin": "https://watch.tataplay.com",
        "Referer": "https://watch.tataplay.com/",
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
        "X-Forwarded-For": "59.178.74.184"
    }
    payload = {
        "kids": [kid],
        "type": "temporary"
    }
    response = requests.post(LICENSE_URL, headers=headers, json=payload)
    try:
        res_data = response.json()
        keys = res_data.get("keys")[0]
        kid_b64 = keys.get("kid")
        key_b64 = keys.get("k")
        decoded_kid = base64.urlsafe_b64decode(kid_b64 + "===")
        decoded_key = base64.urlsafe_b64decode(key_b64 + "===")
        hex_kid = decoded_kid.hex()
        hex_key = decoded_key.hex()
        return {
            "kid_hex": hex_kid,
            "key_hex": hex_key
        }
    except Exception as e:
        return {"error": "Failed to parse license server response", "details": str(e)}

@app.route("/stream.mpd")
def stream_mpd():
    # Proxy the MPD file
    r = requests.get(MPD_URL)
    if r.status_code == 200:
        # Ensure cenc namespace is present
        raw_mpd = r.text
        safe_mpd = inject_namespace_if_missing(raw_mpd)
        mpd_tree = ET.fromstring(safe_mpd)
        ns = {'mpd': 'urn:mpeg:dash:schema:mpd:2011'}
        # Remove ContentProtection from all AdaptationSets
        for adaptation in mpd_tree.findall(".//mpd:AdaptationSet", ns):
            cps = adaptation.findall("mpd:ContentProtection", ns)
            for cp in cps:
                adaptation.remove(cp)
        
        cleaned_mpd = ET.tostring(mpd_tree, encoding="utf-8", xml_declaration=True)
        strip_ns_mpd = strip_ns(cleaned_mpd.decode('utf-8'))
        return Response(strip_ns_mpd, content_type="application/dash+xml")
    return Response("Failed to fetch MPD", status=502)

@app.route("/keys")
def keys():
    try:
        raw_mpd = requests.get(MPD_URL).text
        safe_mpd = inject_namespace_if_missing(raw_mpd)
        kid = extract_default_kid(safe_mpd)
        if not kid:
            return jsonify({"error": "No default_KID found in mp4protection ContentProtection."}), 400
        b64url_kid = hex_kid_to_base64url(kid)
        key_info = send_kid_to_license_server(b64url_kid)
        return jsonify(key_info)
    except Exception as e:
        return jsonify({"error": str(e)}), 500

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000)