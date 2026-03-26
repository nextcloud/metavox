#!/usr/bin/env python3
"""
MetaVox Demo Seeder — Creates 10,000 documents with 25 metadata fields and 30 views.
Usage: python3 seed-demo.py
"""

import json
import subprocess
import random
import sys
import time
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta
from concurrent.futures import ThreadPoolExecutor, as_completed
from urllib.parse import quote

# ============================================================
# Configuration
# ============================================================
NC_URL = "https://3devintravox.hvanextcloudpoc.src.surf-hosted.nl"
NC_USER = "admin"
NC_PASS = "secureadminpass"
SSH_HOST = "145.38.188.218"
SSH_USER = "rdekker"
SSH_KEY = f"{__import__('os').path.expanduser('~')}/.ssh/sur"
OCC = "sudo -u www-data php /var/www/nextcloud/occ"

GF_NAME = "Enterprise Documents"
AUTH = f"{NC_USER}:{NC_PASS}"
OCS_BASE = f"{NC_URL}/ocs/v2.php/apps/metavox/api/v1"
DAV_BASE = f"{NC_URL}/remote.php/dav/files/{NC_USER}"

TOTAL_FILES = 10000

# ============================================================
# Data pools
# ============================================================
AUTHORS = ["Sarah Mitchell", "James Chen", "Emily Rodriguez", "Michael O'Brien", "Aisha Patel",
           "David Kim", "Rachel Thompson", "Carlos Garcia", "Priya Sharma", "Thomas Anderson",
           "Olivia Wilson", "Mohammed Al-Rashid", "Hannah Müller", "Lucas Fernandez", "Sophia Nakamura",
           "Benjamin Wright", "Fatima Hassan", "Alexander Petrov", "Isabella Torres", "William Chang",
           "Nora Eriksen", "Raj Krishnamurthy", "Charlotte Davies", "Daniel Okafor", "Maria Rossi",
           "Ethan Brooks", "Yuki Tanaka", "Amelia Foster", "Samuel Nkomo", "Grace Liu"]

PROJECTS = ["Cloud Migration Phase 2", "Digital Transformation", "ERP Modernization", "Zero Trust Security",
            "Data Lake Initiative", "Customer Portal Redesign", "AI Integration Pilot", "Supply Chain Optimization",
            "Remote Work Infrastructure", "Compliance Automation", "Green IT Strategy", "Mobile-First Platform",
            "API Gateway Rollout", "Identity Federation", "Edge Computing Pilot", "DevOps Maturity",
            "Disaster Recovery Plan", "Cost Optimization Program", "Vendor Consolidation", "Innovation Lab"]

CLIENTS = ["Acme Corporation", "Globex Industries", "Initech Solutions", "Umbrella Holdings", "Vortex Dynamics",
           "Apex Global", "Summit Partners", "Horizon Technologies", "Pinnacle Systems", "Sterling & Associates",
           "Meridian Group", "Atlas Ventures", "Nexus Innovations", "Quantum Enterprises", "Catalyst Partners",
           "Evergreen Solutions", "Pacific Rim Holdings", "Nordic Digital", "Silverline Consulting", "Titan Industries",
           "Aurora Biotech", "Crossroad Capital", "Frontier Analytics", "Keystone Health", "Lumen Energy"]

COST_CENTERS = ["CC-1000", "CC-1100", "CC-1200", "CC-2000", "CC-2100", "CC-3000", "CC-3100", "CC-4000",
                "CC-4100", "CC-4200", "CC-5000", "CC-5100", "CC-6000", "CC-6100", "CC-7000", "CC-7100"]

DEPTS = ["Finance", "HR", "Legal", "IT", "Marketing", "Operations", "Sales", "R&D", "Executive"]
STATUSES = ["Draft", "In review", "Approved", "Published", "Archived", "Expired"]
PRIORITIES = ["Critical", "High", "Medium", "Low"]
REGIONS = ["North America", "Europe", "Asia Pacific", "Latin America", "Middle East", "Africa"]
FISCAL_YEARS = ["FY2023", "FY2024", "FY2025", "FY2026"]
RETENTIONS = ["1 year", "3 years", "5 years", "7 years", "10 years", "Permanent"]
CLASS_OPTS = ["Internal", "External", "Confidential", "Public", "Restricted"]
TAG_OPTS = ["Urgent", "Important", "Follow-up", "Action required", "FYI", "Template", "Compliance"]
APPL_REGS = ["EU", "US", "UK", "APAC", "Global"]

# ============================================================
# Helpers
# ============================================================
def log(msg):
    print(f"[{datetime.now().strftime('%H:%M:%S')}] {msg}", flush=True)

def curl_ocs(method, url, data=None):
    cmd = ["curl", "-s", "-u", AUTH, "-H", "OCS-APIREQUEST: true"]
    if data is not None:
        cmd += ["-H", "Content-Type: application/json", "-X", method, f"{url}?format=json", "-d", json.dumps(data)]
    else:
        cmd += ["-X", method, f"{url}?format=json"]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        return json.loads(result.stdout)
    except:
        return {"raw": result.stdout, "error": result.stderr}

def ocs_get(url): return curl_ocs("GET", url)
def ocs_post(url, data): return curl_ocs("POST", url, data)

def ssh_cmd(cmd):
    result = subprocess.run(
        ["ssh", "-i", SSH_KEY, f"{SSH_USER}@{SSH_HOST}", cmd],
        capture_output=True, text=True
    )
    return result.stdout.strip()

def pick(arr): return random.choice(arr)
def pick_n(arr, n): return ";#".join(random.sample(arr, min(n, len(arr))))
def rand_date(start_year=2023, end_year=2027):
    start = datetime(start_year, 1, 1)
    days = (datetime(end_year, 12, 31) - start).days
    return (start + timedelta(days=random.randint(0, days))).strftime("%Y-%m-%d")

# ============================================================
# Step 1: Create groupfolder
# ============================================================
log(f"Step 1: Creating groupfolder '{GF_NAME}'...")

# Check if it already exists
resp = ocs_get(f"{OCS_BASE}/groupfolders")
gf_id = None
for gf in resp.get("ocs", {}).get("data", []):
    if gf["mount_point"] == GF_NAME:
        gf_id = gf["id"]
        log(f"Groupfolder already exists (ID: {gf_id})")
        break

if gf_id is None:
    output = ssh_cmd(f"{OCC} groupfolders:create '{GF_NAME}'")
    import re
    match = re.search(r'\d+', output)
    if match:
        gf_id = int(match.group())
        log(f"Created groupfolder (ID: {gf_id})")
        ssh_cmd(f"{OCC} groupfolders:group {gf_id} admin write share delete")
        log("Admin group assigned")
    else:
        log(f"ERROR: Could not create groupfolder. Output: {output}")
        sys.exit(1)

# ============================================================
# Step 2: Create fields
# ============================================================
log("Step 2: Creating 25 metadata fields...")

FIELD_DEFS = [
    # Select fields (7)
    ("document_type", "Document type", "select", ["Contract", "Report", "Policy", "Invoice", "Memo", "Proposal", "Manual", "Guideline"]),
    ("department", "Department", "select", ["Finance", "HR", "Legal", "IT", "Marketing", "Operations", "Sales", "R&D", "Executive"]),
    ("status", "Status", "select", ["Draft", "In review", "Approved", "Published", "Archived", "Expired"]),
    ("priority", "Priority", "select", ["Critical", "High", "Medium", "Low"]),
    ("region", "Region", "select", ["North America", "Europe", "Asia Pacific", "Latin America", "Middle East", "Africa"]),
    ("fiscal_year", "Fiscal year", "select", ["FY2023", "FY2024", "FY2025", "FY2026"]),
    ("retention_period", "Retention period", "select", ["1 year", "3 years", "5 years", "7 years", "10 years", "Permanent"]),
    # Text fields (6)
    ("author", "Author", "text", []),
    ("reviewer", "Reviewer", "text", []),
    ("project_name", "Project name", "text", []),
    ("client_name", "Client name", "text", []),
    ("cost_center", "Cost center", "text", []),
    ("reference_number", "Reference number", "text", []),
    # Multiselect fields (3)
    ("classification", "Classification", "multiselect", ["Internal", "External", "Confidential", "Public", "Restricted"]),
    ("tags", "Tags", "multiselect", ["Urgent", "Important", "Follow-up", "Action required", "FYI", "Template", "Compliance"]),
    ("applicable_regions", "Applicable regions", "multiselect", ["EU", "US", "UK", "APAC", "Global"]),
    # Date fields (3)
    ("review_date", "Review date", "date", []),
    ("effective_date", "Effective date", "date", []),
    ("expiry_date", "Expiry date", "date", []),
    # Number fields (3)
    ("version_number", "Version", "number", []),
    ("budget_amount", "Budget amount", "number", []),
    ("page_count", "Page count", "number", []),
    # Checkbox fields (3)
    ("is_approved", "Approved", "checkbox", []),
    ("is_signed", "Signed", "checkbox", []),
    ("requires_action", "Requires action", "checkbox", []),
]

# Get existing fields first
existing = ocs_get(f"{OCS_BASE}/groupfolder-fields")
existing_fields = {}
for f in existing.get("ocs", {}).get("data", []):
    existing_fields[f["field_name"]] = f["id"]

field_ids = {}
for name, label, ftype, options in FIELD_DEFS:
    if name in existing_fields:
        field_ids[name] = existing_fields[name]
        print(f"  Exists: {label} (id={field_ids[name]})")
        continue

    payload = {
        "field_name": name,
        "field_label": label,
        "field_type": ftype,
        "field_description": label,
        "field_options": options,
        "is_required": False,
        "sort_order": 0,
        "applies_to_groupfolder": 0
    }
    resp = ocs_post(f"{OCS_BASE}/groupfolder-fields", payload)
    fid = resp.get("ocs", {}).get("data", {}).get("id")
    if fid:
        field_ids[name] = fid
        print(f"  Created: {label} (id={fid})")
    else:
        print(f"  ERROR creating {label}: {resp}")

log(f"Total fields: {len(field_ids)}")

# ============================================================
# Step 3: Assign fields to groupfolder
# ============================================================
log(f"Step 3: Assigning {len(field_ids)} fields to groupfolder {gf_id}...")

ocs_post(f"{OCS_BASE}/groupfolders/{gf_id}/fields", {"field_ids": list(field_ids.values())})
log("Fields assigned")

# ============================================================
# Step 4: Create 10,000 files via WebDAV
# ============================================================
log(f"Step 4: Creating {TOTAL_FILES} files via WebDAV...")

GF_PATH = f"{DAV_BASE}/{quote(GF_NAME)}"

# Create subdirectories
for d in ["Contracts", "Reports", "Policies", "Invoices", "Memos", "Proposals", "Manuals", "Guidelines"]:
    subprocess.run(["curl", "-s", "-u", AUTH, "-X", "MKCOL", f"{GF_PATH}/{d}"],
                   capture_output=True)

log("Subdirectories created")

# Generate file paths
file_paths = []
def gen_files(category, prefix, ext, count):
    paths = []
    for i in range(1, count + 1):
        client = pick(CLIENTS).replace(" ", "_")
        dept = pick(DEPTS)
        year = random.choice([2023, 2024, 2025, 2026])
        num = f"{i:04d}"
        if category == "Contracts": paths.append(f"{category}/{prefix}_{client}_{year}-{num}.{ext}")
        elif category == "Reports": paths.append(f"{category}/{dept}_{prefix}_Q{random.randint(1,4)}_{year}_{num}.{ext}")
        elif category == "Policies": paths.append(f"{category}/{dept}_{prefix}_v{random.randint(1,5)}_{num}.{ext}")
        elif category == "Invoices": paths.append(f"{category}/INV-{year}-{num}_{client}.{ext}")
        elif category == "Memos": paths.append(f"{category}/Memo_{dept}_{year}{random.randint(1,12):02d}{random.randint(1,28):02d}_{num}.{ext}")
        elif category == "Proposals": paths.append(f"{category}/{prefix}_{client}_{year}_{num}.{ext}")
        elif category == "Manuals": paths.append(f"{category}/{dept}_{prefix}_{year}_{num}.{ext}")
        elif category == "Guidelines": paths.append(f"{category}/{dept}_{prefix}_v{random.randint(1,3)}_{num}.{ext}")
    return paths

file_paths += gen_files("Contracts", "Contract", "pdf", 2000)
file_paths += gen_files("Reports", "Report", "docx", 2000)
file_paths += gen_files("Policies", "Policy", "pdf", 1500)
file_paths += gen_files("Invoices", "Invoice", "pdf", 1500)
file_paths += gen_files("Memos", "Memo", "md", 1500)
file_paths += gen_files("Proposals", "Proposal", "pdf", 500)
file_paths += gen_files("Manuals", "Manual", "pdf", 500)
file_paths += gen_files("Guidelines", "Guideline", "pdf", 500)

log(f"Generated {len(file_paths)} file paths")

# Upload files in parallel
def upload_file(path):
    url = f"{GF_PATH}/{quote(path)}"
    subprocess.run(
        ["curl", "-s", "-u", AUTH, "-X", "PUT", url,
         "-H", "Content-Type: application/octet-stream",
         "-d", f"MetaVox demo document - {path.split('/')[-1]}"],
        capture_output=True
    )
    return path

uploaded = 0
with ThreadPoolExecutor(max_workers=15) as executor:
    futures = {executor.submit(upload_file, p): p for p in file_paths}
    for future in as_completed(futures):
        uploaded += 1
        if uploaded % 500 == 0:
            log(f"  Uploaded {uploaded}/{len(file_paths)} files")

log(f"All {uploaded} files uploaded")

# ============================================================
# Step 5: Get file IDs via PROPFIND
# ============================================================
log("Step 5: Fetching file IDs via PROPFIND...")

propfind_body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>'

result = subprocess.run(
    ["curl", "-s", "-u", AUTH, "-X", "PROPFIND", f"{GF_PATH}/",
     "-H", "Depth: infinity", "-H", "Content-Type: application/xml",
     "-d", propfind_body],
    capture_output=True, text=True
)

root = ET.fromstring(result.stdout)
ns = {"d": "DAV:", "oc": "http://owncloud.org/ns"}

file_id_map = {}
for resp_el in root.findall(".//d:response", ns):
    href = resp_el.find("d:href", ns).text
    fid_el = resp_el.find(".//oc:fileid", ns)
    if fid_el is not None and fid_el.text:
        # Extract path after groupfolder name
        gf_encoded = quote(GF_NAME)
        parts = href.split(f"/{gf_encoded}/")
        if len(parts) > 1 and "." in parts[1]:
            from urllib.parse import unquote
            file_id_map[unquote(parts[1])] = int(fid_el.text)

log(f"Found {len(file_id_map)} file IDs")

# ============================================================
# Step 6: Populate metadata
# ============================================================
log(f"Step 6: Populating metadata for {len(file_id_map)} files...")

def gen_metadata(path):
    cat = path.split("/")[0] if "/" in path else "Other"
    meta = {
        "author": pick(AUTHORS),
        "reviewer": pick(AUTHORS),
        "project_name": pick(PROJECTS),
        "client_name": pick(CLIENTS),
        "cost_center": pick(COST_CENTERS),
        "fiscal_year": pick(FISCAL_YEARS),
        "region": pick(REGIONS),
        "version_number": str(random.randint(1, 10)),
        "page_count": str(random.randint(1, 200)),
        "review_date": rand_date(2025, 2027),
        "effective_date": rand_date(2023, 2026),
        "expiry_date": rand_date(2025, 2028),
    }

    if cat == "Contracts":
        meta.update(document_type="Contract", department=pick(["Legal","Finance","Executive","Sales"]),
                    status=pick(["Approved","Published","In review","Expired"]),
                    priority=pick(["Critical","High","High","Medium"]),
                    retention_period=pick(["7 years","10 years","Permanent"]),
                    classification=pick_n(["Confidential","Internal","Restricted"], random.randint(1,2)),
                    tags=pick_n(["Compliance","Important","Action required"], random.randint(1,2)),
                    applicable_regions=pick_n(APPL_REGS, random.randint(1,3)),
                    budget_amount=str(random.randint(5000, 500000)),
                    reference_number=f"CTR-{pick(FISCAL_YEARS)[-4:]}-{random.randint(1,9999):04d}",
                    is_approved=pick(["1","1","1","0"]), is_signed=pick(["1","1","0"]), requires_action=pick(["0","0","1"]))
    elif cat == "Reports":
        meta.update(document_type="Report", department=pick(DEPTS),
                    status=pick(["Published","Published","Approved","Draft"]),
                    priority=pick(["Medium","Medium","Low","High"]),
                    retention_period=pick(["3 years","5 years","7 years"]),
                    classification=pick_n(["Internal","Public","External"], random.randint(1,2)),
                    tags=pick_n(["FYI","Important","Follow-up"], random.randint(1,2)),
                    applicable_regions=pick_n(APPL_REGS, random.randint(1,2)),
                    budget_amount=str(random.randint(0, 50000)),
                    reference_number=f"RPT-{pick(FISCAL_YEARS)[-4:]}-{random.randint(1,9999):04d}",
                    is_approved=pick(["1","1","0"]), is_signed="0", requires_action=pick(["0","0","0","1"]))
    elif cat == "Policies":
        meta.update(document_type="Policy", department=pick(["HR","Legal","IT","Operations","Executive"]),
                    status=pick(["Approved","Published","In review","Draft"]),
                    priority=pick(["High","Medium","Medium","Critical"]),
                    retention_period=pick(["7 years","10 years","Permanent","Permanent"]),
                    classification=pick_n(["Internal","Confidential","Restricted"], random.randint(1,2)),
                    tags=pick_n(["Compliance","Important","Template"], random.randint(1,3)),
                    applicable_regions=pick_n(APPL_REGS, random.randint(2,4)),
                    budget_amount="0",
                    reference_number=f"POL-{pick(FISCAL_YEARS)[-4:]}-{random.randint(1,999):03d}",
                    is_approved=pick(["1","1","0"]), is_signed=pick(["1","0"]), requires_action=pick(["0","1"]))
    elif cat == "Invoices":
        meta.update(document_type="Invoice", department=pick(["Finance","Finance","Operations","Sales"]),
                    status=pick(STATUSES), priority=pick(["Medium","Low","High","Low"]),
                    retention_period=pick(["5 years","7 years","10 years"]),
                    classification=pick_n(["Internal","Confidential"], random.randint(1,2)),
                    tags=pick_n(["Action required","Follow-up","Urgent"], random.randint(1,2)),
                    applicable_regions=pick_n(APPL_REGS, 1),
                    budget_amount=str(random.randint(100, 250000)),
                    reference_number=f"INV-{pick(FISCAL_YEARS)[-4:]}-{random.randint(1,99999):05d}",
                    is_approved=pick(["1","0","1","1"]), is_signed=pick(["1","0"]), requires_action=pick(["1","0","0"]))
    elif cat == "Memos":
        meta.update(document_type="Memo", department=pick(DEPTS),
                    status=pick(["Draft","Draft","In review","Approved"]),
                    priority=pick(["Low","Low","Medium","Medium"]),
                    retention_period=pick(["1 year","3 years"]),
                    classification="Internal", tags=pick_n(["FYI","Follow-up","Action required"], random.randint(1,2)),
                    applicable_regions="Global", budget_amount="0",
                    reference_number=f"MEM-{pick(FISCAL_YEARS)[-4:]}-{random.randint(1,9999):04d}",
                    is_approved="0", is_signed="0", requires_action=pick(["1","0"]),
                    page_count=str(random.randint(1, 5)))
    elif cat == "Proposals":
        meta.update(document_type="Proposal", department=pick(["Sales","Marketing","Executive","R&D"]),
                    status=pick(["Draft","In review","Approved","Published"]),
                    priority=pick(["High","High","Critical","Medium"]),
                    retention_period=pick(["3 years","5 years"]),
                    classification=pick_n(["Confidential","Internal","External"], random.randint(1,2)),
                    tags=pick_n(["Important","Action required","Urgent"], random.randint(1,2)),
                    applicable_regions=pick_n(APPL_REGS, random.randint(1,3)),
                    budget_amount=str(random.randint(10000, 1000000)),
                    reference_number=f"PRP-{pick(FISCAL_YEARS)[-4:]}-{random.randint(1,999):03d}",
                    is_approved=pick(["0","0","1"]), is_signed="0", requires_action=pick(["1","1","0"]))
    elif cat == "Manuals":
        meta.update(document_type="Manual", department=pick(["IT","HR","Operations"]),
                    status=pick(["Published","Published","Approved"]),
                    priority=pick(["Medium","Low"]),
                    retention_period=pick(["Permanent","10 years"]),
                    classification=pick_n(["Internal","Public"], random.randint(1,2)),
                    tags=pick_n(["Template","FYI","Compliance"], random.randint(1,2)),
                    applicable_regions="Global", budget_amount="0",
                    reference_number=f"MAN-{pick(FISCAL_YEARS)[-4:]}-{random.randint(1,999):03d}",
                    is_approved="1", is_signed="0", requires_action="0",
                    page_count=str(random.randint(20, 300)))
    else:  # Guidelines
        meta.update(document_type="Guideline", department=pick(DEPTS),
                    status=pick(["Approved","Published","In review"]),
                    priority=pick(["Medium","Low","High"]),
                    retention_period=pick(["Permanent","10 years","7 years"]),
                    classification=pick_n(["Internal","Public","External"], random.randint(1,2)),
                    tags=pick_n(["Compliance","Template","Important"], random.randint(1,3)),
                    applicable_regions=pick_n(APPL_REGS, random.randint(2,4)),
                    budget_amount="0",
                    reference_number=f"GDL-{pick(FISCAL_YEARS)[-4:]}-{random.randint(1,999):03d}",
                    is_approved=pick(["1","1","0"]), is_signed="0", requires_action=pick(["0","0","1"]))
    return meta

# Process in batches of 100
items = list(file_id_map.items())
total = len(items)

for i in range(0, total, 100):
    batch = items[i:i+100]
    updates = [{"file_id": fid, "metadata": gen_metadata(path)} for path, fid in batch]

    resp = subprocess.run(
        ["curl", "-s", "-u", AUTH, "-H", "OCS-APIREQUEST: true",
         "-H", "Content-Type: application/json", "-X", "POST",
         f"{OCS_BASE}/files/metadata/batch-update?format=json",
         "-d", json.dumps({"updates": updates})],
        capture_output=True, text=True
    )

    done = min(i + 100, total)
    if done % 1000 == 0 or done == total:
        log(f"  Metadata: {done}/{total} files")

log("Metadata population complete")

# ============================================================
# Step 7: Create 30 views
# ============================================================
log("Step 7: Creating 30 views...")

def make_cols(field_names):
    return [{"field_id": field_ids[f], "visible": True, "filterable": True} for f in field_names if f in field_ids]

def make_filter(field_name, values):
    if field_name not in field_ids:
        return {}
    return {str(field_ids[field_name]): values if isinstance(values, list) else [values]}

def create_view(name, columns, sort_field, sort_order, filters=None):
    payload = {
        "name": name,
        "columns": make_cols(columns),
        "filters": filters or {},
        "sort_field": sort_field,
        "sort_order": sort_order
    }
    resp = ocs_post(f"{OCS_BASE}/groupfolders/{gf_id}/views", payload)
    vid = resp.get("ocs", {}).get("data", {}).get("id", "?")
    print(f"  View: {name} (id={vid})")

# 30 views
create_view("All documents", ["document_type","department","status","author","priority","region"], "author", "asc")
create_view("By department", ["department","document_type","status","author","cost_center"], "department", "asc")
create_view("By status", ["status","document_type","department","author","priority"], "status", "asc")
create_view("Contracts", ["document_type","department","author","client_name","classification","expiry_date","is_signed"],
            "author", "asc", make_filter("document_type", ["Contract"]))
create_view("Reports", ["document_type","department","author","fiscal_year","region","page_count"],
            "fiscal_year", "desc", make_filter("document_type", ["Report"]))
create_view("Policies & guidelines", ["document_type","department","status","version_number","retention_period","is_approved"],
            "department", "asc", make_filter("document_type", ["Policy", "Guideline"]))
create_view("Invoices", ["document_type","department","author","budget_amount","fiscal_year","reference_number"],
            "budget_amount", "desc", make_filter("document_type", ["Invoice"]))
create_view("Draft documents", ["status","document_type","department","author","priority","requires_action"],
            "priority", "asc", make_filter("status", ["Draft"]))
create_view("Approved & published", ["status","document_type","department","author","is_approved","effective_date"],
            "department", "asc", make_filter("status", ["Approved", "Published"]))
create_view("Archived", ["status","document_type","department","author","expiry_date","retention_period"],
            "expiry_date", "desc", make_filter("status", ["Archived"]))
create_view("Expired items", ["status","document_type","expiry_date","department","requires_action"],
            "expiry_date", "asc", make_filter("status", ["Expired"]))
create_view("High priority", ["priority","document_type","department","author","status","requires_action"],
            "document_type", "asc", make_filter("priority", ["Critical", "High"]))
create_view("Finance department", ["department","document_type","status","budget_amount","fiscal_year","cost_center"],
            "budget_amount", "desc", make_filter("department", ["Finance"]))
create_view("Legal department", ["department","document_type","status","author","classification","is_signed"],
            "document_type", "asc", make_filter("department", ["Legal"]))
create_view("IT department", ["department","document_type","status","author","tags","project_name"],
            "author", "asc", make_filter("department", ["IT"]))
create_view("HR department", ["department","document_type","status","author","version_number","retention_period"],
            "status", "asc", make_filter("department", ["HR"]))
create_view("Marketing & Sales", ["department","document_type","author","client_name","tags","region"],
            "author", "asc", make_filter("department", ["Marketing", "Sales"]))
create_view("Executive overview", ["department","document_type","status","priority","budget_amount","region"],
            "priority", "asc", make_filter("department", ["Executive"]))
create_view("Confidential files", ["classification","document_type","department","author","is_signed","retention_period"],
            "department", "asc", make_filter("classification", ["Confidential"]))
create_view("Public documents", ["classification","document_type","department","author","applicable_regions"],
            "document_type", "asc", make_filter("classification", ["Public"]))
create_view("Needs review", ["status","review_date","document_type","department","reviewer","priority"],
            "review_date", "asc", make_filter("status", ["In review"]))
create_view("Upcoming reviews", ["review_date","document_type","department","author","status"], "review_date", "asc")
create_view("By project", ["project_name","document_type","department","author","client_name","budget_amount"], "project_name", "asc")
create_view("Action required", ["requires_action","tags","document_type","department","author","priority"],
            "priority", "asc", make_filter("requires_action", ["1"]))
create_view("Compliance docs", ["tags","document_type","department","classification","retention_period","applicable_regions"],
            "department", "asc", make_filter("tags", ["Compliance"]))
create_view("Templates", ["tags","document_type","department","version_number","page_count"],
            "document_type", "asc", make_filter("tags", ["Template"]))
create_view("Not yet approved", ["is_approved","document_type","department","author","reviewer","status"],
            "department", "asc", make_filter("is_approved", ["0"]))
create_view("Budget overview", ["budget_amount","department","document_type","project_name","client_name","fiscal_year"], "budget_amount", "desc")
create_view("Full metadata",
            ["document_type","department","status","priority","region","fiscal_year","retention_period",
             "author","reviewer","project_name","client_name","cost_center","reference_number",
             "classification","tags","applicable_regions","review_date","effective_date","expiry_date",
             "version_number","budget_amount","page_count","is_approved","is_signed","requires_action"],
            "author", "asc")
create_view("EU regulations", ["applicable_regions","document_type","department","classification","retention_period","effective_date"],
            "effective_date", "desc", make_filter("applicable_regions", ["EU"]))

# ============================================================
# Summary
# ============================================================
log("=" * 50)
log("Demo environment setup complete!")
log("=" * 50)
log(f"Groupfolder: {GF_NAME} (ID: {gf_id})")
log(f"Fields: {len(field_ids)} metadata fields")
log(f"Files: {len(file_id_map)} documents")
log("Views: 30 custom views")
log(f"URL: {NC_URL}/apps/files/?dir=/{quote(GF_NAME)}")
log("=" * 50)
