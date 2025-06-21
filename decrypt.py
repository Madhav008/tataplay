import requests
import xml.etree.ElementTree as ET
import re
import base64
def hex_kid_to_base64url(hex_kid):
    # Remove dashes and decode to bytes
    kid_bytes = bytes.fromhex(hex_kid.replace('-', ''))
    
    # Encode to base64 URL-safe (no padding)
    b64url_kid = base64.urlsafe_b64encode(kid_bytes).rstrip(b'=').decode('utf-8')
    
    return b64url_kid

def send_kid_to_license_server(kid):
    url = "https://tp.drmlive-01.workers.dev/?id=40"
    
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

    response = requests.post(url, headers=headers, json=payload)

    print(f"Status Code: {response.status_code}")
    try:
        res_data = response.json()
        keys = res_data.get("keys")[0]
        kid = keys.get("kid")
        key = keys.get("k")
        
        print(f"Base64 KID: {kid}")
        print(f"Base64 KEY: {key}")

        # Decode base64 to bytes
        decoded_kid = base64.urlsafe_b64decode(kid + "===")  # ensures proper padding
        decoded_key = base64.urlsafe_b64decode(key + "===")

        # Convert to hex
        hex_kid = decoded_kid.hex()
        hex_key = decoded_key.hex()

        print(f"Decoded KID (hex): {hex_kid}")
        print(f"Decoded Key (hex): {hex_key}")
        return hex_kid, hex_key
    except Exception as e:
        print("Non-JSON Response:")
        print(response.text)


def get_mpd_content(url):
    response = requests.get(url)
    response.raise_for_status()
    return response.text

def inject_namespace_if_missing(xml_text):
    if 'xmlns:cenc' not in xml_text:
        xml_text = re.sub(r'(<MPD[^>]*?)>', r'\1 xmlns:cenc="urn:mpeg:cenc:2013">', xml_text)
    return xml_text

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

# Example usage
def getKey():
    mpd_url = "https://tataplay.ipl2025.space/tataplay/manifest.mpd?id=40"

    print(f"Fetching MPD from: {mpd_url}")
    try:
        raw_mpd = get_mpd_content(mpd_url)
        safe_mpd = inject_namespace_if_missing(raw_mpd)
        kid = extract_default_kid(safe_mpd)

        if kid:
            print(f"[+] Extracted default_KID: {kid}")
            b64url_kid = hex_kid_to_base64url(kid)
            return send_kid_to_license_server(b64url_kid)
        else:
            print("[-] No default_KID found in mp4protection ContentProtection.")
    except Exception as e:
        print(f"[!] Error: {e}")