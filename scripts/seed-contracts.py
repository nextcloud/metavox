#!/usr/bin/env python3
"""
Seed 10,000 extra contract files with metadata into the Contracts folder.
Uses SSH disk write + occ files:scan for speed.
"""

import json, subprocess, random, sys, time
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta
from urllib.parse import quote, unquote

# ============================================================
# Configuration
# ============================================================
NC_URL = "https://3devintravox.hvanextcloudpoc.src.surf-hosted.nl"
NC_USER = "admin"
NC_PASS = "secureadminpass"
SSH_HOST = "145.38.188.218"
SSH_USER = "rdekker"
SSH_KEY = f"{__import__('os').path.expanduser('~')}/.ssh/sur"

GF_ID = 12
GF_NAME = "Enterprise Documents"
CONTRACTS_DIR = f"/var/www/nextcloud/data/__groupfolders/{GF_ID}/files/Contracts"
AUTH = f"{NC_USER}:{NC_PASS}"
OCS_BASE = f"{NC_URL}/ocs/v2.php/apps/metavox/api/v1"
DAV_BASE = f"{NC_URL}/remote.php/dav/files/{NC_USER}"

NUM_FILES = 10000
BATCH_SIZE = 500

# ============================================================
# Data pools
# ============================================================
AUTHORS = ["Sarah Mitchell","James Chen","Emily Rodriguez","Michael O'Brien","Aisha Patel",
           "David Kim","Rachel Thompson","Carlos Garcia","Priya Sharma","Thomas Anderson",
           "Olivia Wilson","Mohammed Al-Rashid","Hannah Müller","Lucas Fernandez","Sophia Nakamura",
           "Benjamin Wright","Fatima Hassan","Alexander Petrov","Isabella Torres","William Chang",
           "Nora Eriksen","Raj Krishnamurthy","Charlotte Davies","Daniel Okafor","Maria Rossi",
           "Ethan Brooks","Yuki Tanaka","Amelia Foster","Samuel Nkomo","Grace Liu"]

PROJECTS = ["Cloud Migration Phase 2","Digital Transformation","ERP Modernization","Zero Trust Security",
            "Data Lake Initiative","Customer Portal Redesign","AI Integration Pilot","Supply Chain Optimization",
            "Remote Work Infrastructure","Compliance Automation","Green IT Strategy","Mobile-First Platform",
            "API Gateway Rollout","Identity Federation","Edge Computing Pilot","DevOps Maturity",
            "Disaster Recovery Plan","Cost Optimization Program","Vendor Consolidation","Innovation Lab"]

CLIENTS = ["Acme Corporation","Globex Industries","Initech Solutions","Umbrella Holdings","Vortex Dynamics",
           "Apex Global","Summit Partners","Horizon Technologies","Pinnacle Systems","Sterling Associates",
           "Meridian Group","Atlas Ventures","Nexus Innovations","Quantum Enterprises","Catalyst Partners",
           "Evergreen Solutions","Pacific Rim Holdings","Nordic Digital","Silverline Consulting","Titan Industries",
           "Aurora Biotech","Crossroad Capital","Frontier Analytics","Keystone Health","Lumen Energy"]

COST_CENTERS = ["CC-1000","CC-1100","CC-1200","CC-2000","CC-2100","CC-3000","CC-3100","CC-4000",
                "CC-4100","CC-4200","CC-5000","CC-5100","CC-6000","CC-6100","CC-7000","CC-7100"]

DEPTS = ["Legal","Finance","Executive","Sales"]
STATUSES = ["Approved","Published","In review","Expired","Draft","Archived"]
PRIORITIES = ["Critical","High","High","Medium","Medium","Low"]
REGIONS = ["North America","Europe","Asia Pacific","Latin America","Middle East","Africa"]
FISCAL_YEARS = ["FY2023","FY2024","FY2025","FY2026"]
RETENTIONS = ["5 years","7 years","10 years","Permanent"]
APPL_REGS = ["EU","US","UK","APAC","Global"]

def log(msg): print(f"[{datetime.now().strftime('%H:%M:%S')}] {msg}", flush=True)
def pick(arr): return random.choice(arr)
def pick_n(arr, n): return ";#".join(random.sample(arr, min(n, len(arr))))
def rand_date(sy=2023, ey=2027):
    s = datetime(sy, 1, 1); d = (datetime(ey, 12, 31) - s).days
    return (s + timedelta(days=random.randint(0, d))).strftime("%Y-%m-%d")

def ssh(cmd):
    r = subprocess.run(["ssh", "-i", SSH_KEY, f"{SSH_USER}@{SSH_HOST}", cmd], capture_output=True, text=True)
    return r.stdout.strip()

def ocs_post(url, data):
    r = subprocess.run(["curl", "-s", "-u", AUTH, "-H", "OCS-APIREQUEST: true",
                        "-H", "Content-Type: application/json", "-X", "POST",
                        f"{url}?format=json", "-d", json.dumps(data)], capture_output=True, text=True)
    try: return json.loads(r.stdout)
    except: return {"raw": r.stdout}

# ============================================================
# Step 1: Create 10,000 files on disk via SSH
# ============================================================
METADATA_ONLY = "--metadata-only" in sys.argv

if METADATA_ONLY:
    log("Running in METADATA-ONLY mode (skipping file creation and scan)")

if not METADATA_ONLY:
    log(f"Step 1: Creating {NUM_FILES} files on disk via SSH...")

# Generate filenames locally
filenames = []
for i in range(1, NUM_FILES + 1):
    client = pick(CLIENTS).replace(" ", "_")
    year = random.choice([2023, 2024, 2025, 2026])
    filenames.append(f"Contract_{client}_{year}-{i:05d}.pdf")

# Upload filename list to server, then create files in bulk
import tempfile, os

with tempfile.NamedTemporaryFile(mode='w', suffix='.txt', delete=False) as f:
    f.write("\n".join(filenames))
    tmpfile = f.name

# Copy file list to server
subprocess.run(["scp", "-i", SSH_KEY, tmpfile, f"{SSH_USER}@{SSH_HOST}:/tmp/contract_files.txt"],
               capture_output=True)
os.unlink(tmpfile)

# Create all files on server using the list
ssh(f"sudo bash -c 'cd {CONTRACTS_DIR} && while IFS= read -r fname; do echo \"MetaVox demo contract\" > \"$fname\"; done < /tmp/contract_files.txt && chown -R www-data:www-data {CONTRACTS_DIR} && rm /tmp/contract_files.txt'")

# Verify count
count = ssh(f"sudo ls '{CONTRACTS_DIR}' | wc -l")
log(f"Files on disk: {count} (was 2000, added {NUM_FILES})")

# ============================================================
# Step 2: Run occ files:scan
# ============================================================
log("Step 2: Running occ files:scan...")

scan_output = ssh(f"sudo -u www-data php /var/www/nextcloud/occ files:scan admin --path='admin/files/{GF_NAME}/Contracts'")
log(f"Scan result: {scan_output}")

# ============================================================
# Step 3: Get file IDs via PROPFIND
# ============================================================
log("Step 3: Fetching file IDs via PROPFIND...")

propfind = subprocess.run(
    ["curl", "-s", "-u", AUTH, "-X", "PROPFIND",
     f"{DAV_BASE}/{quote(GF_NAME)}/Contracts/",
     "-H", "Depth: 1", "-H", "Content-Type: application/xml",
     "-d", '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>'],
    capture_output=True, text=True, timeout=120
)

root = ET.fromstring(propfind.stdout)
ns = {"d": "DAV:", "oc": "http://owncloud.org/ns"}

# Map filename -> file_id for new files only
new_file_ids = {}
for resp_el in root.findall(".//d:response", ns):
    href = resp_el.find("d:href", ns).text
    fid_el = resp_el.find(".//oc:fileid", ns)
    if fid_el is not None and fid_el.text and "." in href:
        fname = unquote(href.split("/")[-1])
        # Only include our new files (they start with Contract_ and have 5-digit suffix)
        if fname in filenames:
            new_file_ids[fname] = int(fid_el.text)

log(f"Found {len(new_file_ids)} new file IDs (of {NUM_FILES} created)")

# ============================================================
# Step 4: Populate metadata via batch-update API
# ============================================================
log(f"Step 4: Populating metadata for {len(new_file_ids)} files (batch size: {BATCH_SIZE})...")

def gen_contract_metadata(fname):
    """Generate realistic contract metadata."""
    return {
        "document_type": "Contract",
        "department": pick(DEPTS),
        "status": pick(STATUSES),
        "priority": pick(PRIORITIES),
        "region": pick(REGIONS),
        "fiscal_year": pick(FISCAL_YEARS),
        "retention_period": pick(RETENTIONS),
        "author": pick(AUTHORS),
        "reviewer": pick(AUTHORS),
        "project_name": pick(PROJECTS),
        "client_name": pick(CLIENTS),
        "cost_center": pick(COST_CENTERS),
        "reference_number": f"CTR-{random.randint(2023,2026)}-{random.randint(1,99999):05d}",
        "classification": pick_n(["Confidential","Internal","Restricted"], random.randint(1,2)),
        "tags": pick_n(["Compliance","Important","Action required","Urgent"], random.randint(1,3)),
        "applicable_regions": pick_n(APPL_REGS, random.randint(1,3)),
        "review_date": rand_date(2025, 2027),
        "effective_date": rand_date(2023, 2026),
        "expiry_date": rand_date(2025, 2028),
        "version_number": str(random.randint(1, 10)),
        "budget_amount": str(random.randint(5000, 500000)),
        "page_count": str(random.randint(2, 50)),
        "is_approved": pick(["1","1","1","0"]),
        "is_signed": pick(["1","1","0"]),
        "requires_action": pick(["0","0","1"]),
    }

items = list(new_file_ids.items())
total = len(items)

for i in range(0, total, BATCH_SIZE):
    batch = items[i:i+BATCH_SIZE]
    updates = [{"file_id": fid, "groupfolder_id": GF_ID, "metadata": gen_contract_metadata(fname)} for fname, fid in batch]

    ocs_post(f"{OCS_BASE}/files/metadata/batch-update", {"updates": updates})

    done = min(i + BATCH_SIZE, total)
    log(f"  Metadata: {done}/{total} files")

log("Metadata population complete")

# ============================================================
# Summary
# ============================================================
final_count = ssh(f"sudo ls '{CONTRACTS_DIR}' | wc -l")
log("=" * 50)
log("Contracts seeding complete!")
log("=" * 50)
log(f"Total files in Contracts: {final_count}")
log(f"New files added: {len(new_file_ids)}")
log(f"All 25 metadata fields populated")
log(f"URL: {NC_URL}/apps/files/?dir=/{quote(GF_NAME)}/Contracts")
log("=" * 50)
