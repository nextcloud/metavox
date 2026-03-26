#!/bin/bash
# MetaVox Demo Seeder — Creates 10,000 documents with 25 metadata fields and 30 views
# Usage: ./seed-demo.sh
set -euo pipefail

# ============================================================
# Configuration
# ============================================================
NC_URL="https://3devintravox.hvanextcloudpoc.src.surf-hosted.nl"
NC_USER="admin"
NC_PASS="admin"
SSH_HOST="145.38.188.218"
SSH_USER="rdekker"
SSH_KEY="$HOME/.ssh/sur"
OCC="sudo -u www-data php /var/www/nextcloud/occ"

GF_NAME="Enterprise Documents"
AUTH="${NC_USER}:${NC_PASS}"
OCS_BASE="${NC_URL}/ocs/v2.php/apps/metavox/api/v1"
DAV_BASE="${NC_URL}/remote.php/dav/files/${NC_USER}"

TOTAL_FILES=10000
BATCH_SIZE=100

# ============================================================
# Helpers
# ============================================================
ocs_get()  { curl -s -u "$AUTH" -H "OCS-APIREQUEST: true" "$1?format=json"; }
ocs_post() { curl -s -u "$AUTH" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "$1?format=json" -d "$2"; }
ocs_put()  { curl -s -u "$AUTH" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X PUT "$1?format=json" -d "$2"; }

log() { echo "[$(date +%H:%M:%S)] $1"; }

# ============================================================
# Data pools
# ============================================================
AUTHORS=("Sarah Mitchell" "James Chen" "Emily Rodriguez" "Michael O'Brien" "Aisha Patel"
         "David Kim" "Rachel Thompson" "Carlos Garcia" "Priya Sharma" "Thomas Anderson"
         "Olivia Wilson" "Mohammed Al-Rashid" "Hannah Müller" "Lucas Fernandez" "Sophia Nakamura"
         "Benjamin Wright" "Fatima Hassan" "Alexander Petrov" "Isabella Torres" "William Chang"
         "Nora Eriksen" "Raj Krishnamurthy" "Charlotte Davies" "Daniel Okafor" "Maria Rossi"
         "Ethan Brooks" "Yuki Tanaka" "Amelia Foster" "Samuel Nkomo" "Grace Liu")

PROJECTS=("Cloud Migration Phase 2" "Digital Transformation" "ERP Modernization" "Zero Trust Security"
          "Data Lake Initiative" "Customer Portal Redesign" "AI Integration Pilot" "Supply Chain Optimization"
          "Remote Work Infrastructure" "Compliance Automation" "Green IT Strategy" "Mobile-First Platform"
          "API Gateway Rollout" "Identity Federation" "Edge Computing Pilot" "DevOps Maturity"
          "Disaster Recovery Plan" "Cost Optimization Program" "Vendor Consolidation" "Innovation Lab")

CLIENTS=("Acme Corporation" "Globex Industries" "Initech Solutions" "Umbrella Holdings" "Vortex Dynamics"
         "Apex Global" "Summit Partners" "Horizon Technologies" "Pinnacle Systems" "Sterling & Associates"
         "Meridian Group" "Atlas Ventures" "Nexus Innovations" "Quantum Enterprises" "Catalyst Partners"
         "Evergreen Solutions" "Pacific Rim Holdings" "Nordic Digital" "Silverline Consulting" "Titan Industries"
         "Aurora Biotech" "Crossroad Capital" "Frontier Analytics" "Keystone Health" "Lumen Energy")

COST_CENTERS=("CC-1000" "CC-1100" "CC-1200" "CC-2000" "CC-2100" "CC-3000" "CC-3100" "CC-4000"
              "CC-4100" "CC-4200" "CC-5000" "CC-5100" "CC-6000" "CC-6100" "CC-7000" "CC-7100")

DEPARTMENTS=("Finance" "HR" "Legal" "IT" "Marketing" "Operations" "Sales" "R&D" "Executive")
STATUSES=("Draft" "In review" "Approved" "Published" "Archived" "Expired")
PRIORITIES=("Critical" "High" "Medium" "Low")
REGIONS=("North America" "Europe" "Asia Pacific" "Latin America" "Middle East" "Africa")
FISCAL_YEARS=("FY2023" "FY2024" "FY2025" "FY2026")
RETENTIONS=("1 year" "3 years" "5 years" "7 years" "10 years" "Permanent")
CLASSIFICATIONS=("Internal" "External" "Confidential" "Public" "Restricted")
TAGS=("Urgent" "Important" "Follow-up" "Action required" "FYI" "Template" "Compliance")
APPL_REGIONS=("EU" "US" "UK" "APAC" "Global")

pick() { local arr=("$@"); echo "${arr[$((RANDOM % ${#arr[@]}))]}" ; }
pick_n() {
    local -a arr=("${@:2}")
    local n=$1
    local result=""
    local -a shuffled=()
    for i in "${!arr[@]}"; do shuffled+=("$i"); done
    for ((i=${#shuffled[@]}-1; i>0; i--)); do
        j=$((RANDOM % (i + 1)))
        tmp=${shuffled[$i]}; shuffled[$i]=${shuffled[$j]}; shuffled[$j]=$tmp
    done
    for ((i=0; i<n && i<${#arr[@]}; i++)); do
        [ -n "$result" ] && result="${result};#"
        result="${result}${arr[${shuffled[$i]}]}"
    done
    echo "$result"
}
rand_date() {
    local start=$1 range=$2
    local ts=$((start + RANDOM % range))
    date -r $ts "+%Y-%m-%d" 2>/dev/null || date -d "@$ts" "+%Y-%m-%d" 2>/dev/null
}

# ============================================================
# Step 1: Create groupfolder via SSH
# ============================================================
log "Step 1: Creating groupfolder '${GF_NAME}'..."

GF_ID=$(ssh -i "$SSH_KEY" "${SSH_USER}@${SSH_HOST}" "${OCC} groupfolders:create '${GF_NAME}'" 2>/dev/null | grep -oE '[0-9]+' | head -1)

if [ -z "$GF_ID" ]; then
    log "Groupfolder may already exist. Trying to find ID..."
    GF_ID=$(ocs_get "${OCS_BASE}/groupfolders" | python3 -c "
import sys, json
data = json.load(sys.stdin)['ocs']['data']
for gf in data:
    if gf['mount_point'] == '${GF_NAME}':
        print(gf['id'])
        break
" 2>/dev/null)
fi

if [ -z "$GF_ID" ]; then
    echo "ERROR: Could not create or find groupfolder"; exit 1
fi

log "Groupfolder ID: ${GF_ID}"

# Assign admin group with full permissions
ssh -i "$SSH_KEY" "${SSH_USER}@${SSH_HOST}" "${OCC} groupfolders:group ${GF_ID} admin write share delete" 2>/dev/null || true
log "Admin group assigned to groupfolder"

# ============================================================
# Step 2: Create fields via API
# ============================================================
log "Step 2: Creating 25 metadata fields..."

declare -A FIELD_IDS

create_field() {
    local name="$1" label="$2" type="$3" options="$4"
    local opts_json="[]"
    if [ -n "$options" ]; then
        opts_json=$(echo "$options" | python3 -c "import sys,json; print(json.dumps(sys.stdin.read().strip().split(',')))")
    fi
    local payload=$(python3 -c "
import json
print(json.dumps({
    'field_name': '${name}',
    'field_label': '${label}',
    'field_type': '${type}',
    'field_description': '${label}',
    'field_options': ${opts_json},
    'is_required': False,
    'sort_order': 0,
    'applies_to_groupfolder': 0
}))
")
    local resp=$(ocs_post "${OCS_BASE}/groupfolder-fields" "$payload")
    local fid=$(echo "$resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['ocs']['data']['id'])" 2>/dev/null)
    if [ -n "$fid" ] && [ "$fid" != "None" ]; then
        FIELD_IDS[$name]=$fid
        echo "  Created: ${label} (id=${fid})"
    else
        echo "  WARN: ${label} may already exist, trying to find..."
        fid=$(ocs_get "${OCS_BASE}/groupfolder-fields" | python3 -c "
import sys, json
data = json.load(sys.stdin)['ocs']['data']
for f in data:
    if f['field_name'] == '${name}':
        print(f['id'])
        break
" 2>/dev/null)
        if [ -n "$fid" ]; then
            FIELD_IDS[$name]=$fid
            echo "  Found: ${label} (id=${fid})"
        fi
    fi
}

# Select fields (7)
create_field "document_type" "Document type" "select" "Contract,Report,Policy,Invoice,Memo,Proposal,Manual,Guideline"
create_field "department" "Department" "select" "Finance,HR,Legal,IT,Marketing,Operations,Sales,R&D,Executive"
create_field "status" "Status" "select" "Draft,In review,Approved,Published,Archived,Expired"
create_field "priority" "Priority" "select" "Critical,High,Medium,Low"
create_field "region" "Region" "select" "North America,Europe,Asia Pacific,Latin America,Middle East,Africa"
create_field "fiscal_year" "Fiscal year" "select" "FY2023,FY2024,FY2025,FY2026"
create_field "retention_period" "Retention period" "select" "1 year,3 years,5 years,7 years,10 years,Permanent"

# Text fields (6)
create_field "author" "Author" "text" ""
create_field "reviewer" "Reviewer" "text" ""
create_field "project_name" "Project name" "text" ""
create_field "client_name" "Client name" "text" ""
create_field "cost_center" "Cost center" "text" ""
create_field "reference_number" "Reference number" "text" ""

# Multiselect fields (3)
create_field "classification" "Classification" "multiselect" "Internal,External,Confidential,Public,Restricted"
create_field "tags" "Tags" "multiselect" "Urgent,Important,Follow-up,Action required,FYI,Template,Compliance"
create_field "applicable_regions" "Applicable regions" "multiselect" "EU,US,UK,APAC,Global"

# Date fields (3)
create_field "review_date" "Review date" "date" ""
create_field "effective_date" "Effective date" "date" ""
create_field "expiry_date" "Expiry date" "date" ""

# Number fields (3)
create_field "version_number" "Version" "number" ""
create_field "budget_amount" "Budget amount" "number" ""
create_field "page_count" "Page count" "number" ""

# Checkbox fields (3)
create_field "is_approved" "Approved" "checkbox" ""
create_field "is_signed" "Signed" "checkbox" ""
create_field "requires_action" "Requires action" "checkbox" ""

log "Created ${#FIELD_IDS[@]} fields"

# ============================================================
# Step 3: Assign fields to groupfolder
# ============================================================
log "Step 3: Assigning fields to groupfolder ${GF_ID}..."

FIELD_IDS_JSON=$(python3 -c "
import json
ids = [$(IFS=,; echo "${FIELD_IDS[*]}")]
print(json.dumps({'field_ids': ids}))
")
ocs_post "${OCS_BASE}/groupfolders/${GF_ID}/fields" "$FIELD_IDS_JSON" > /dev/null
log "All fields assigned"

# ============================================================
# Step 4: Create 10,000 files via WebDAV
# ============================================================
log "Step 4: Creating ${TOTAL_FILES} files via WebDAV..."

GF_PATH="${DAV_BASE}/${GF_NAME}"

# Create subdirectories
for dir in Contracts Reports Policies Invoices Memos Proposals Manuals Guidelines; do
    curl -s -u "$AUTH" -X MKCOL "${GF_PATH}/${dir}" > /dev/null 2>&1 || true
done
log "Subdirectories created"

# Generate file list
TMPDIR_SEED=$(mktemp -d)
FILE_LIST="${TMPDIR_SEED}/files.txt"

generate_files() {
    local category=$1 prefix=$2 ext=$3 count=$4
    for i in $(seq 1 $count); do
        local client=$(pick "${CLIENTS[@]}")
        local dept=$(pick "${DEPARTMENTS[@]}")
        local year=$((2023 + RANDOM % 4))
        local num=$(printf "%04d" $i)
        case $category in
            Contracts) echo "${category}/${prefix}_${client// /_}_${year}-${num}.${ext}" ;;
            Reports)   echo "${category}/${dept}_${prefix}_Q$((1 + RANDOM % 4))_${year}_${num}.${ext}" ;;
            Policies)  echo "${category}/${dept}_${prefix}_v$((1 + RANDOM % 5))_${num}.${ext}" ;;
            Invoices)  echo "${category}/INV-${year}-${num}_${client// /_}.${ext}" ;;
            Memos)     echo "${category}/Memo_${dept}_$(date +%Y%m%d)_${num}.${ext}" ;;
            Proposals) echo "${category}/${prefix}_${client// /_}_${year}_${num}.${ext}" ;;
            Manuals)   echo "${category}/${dept}_${prefix}_${year}_${num}.${ext}" ;;
            Guidelines) echo "${category}/${dept}_${prefix}_v$((1 + RANDOM % 3))_${num}.${ext}" ;;
        esac
    done
}

generate_files Contracts "Contract" "pdf" 2000 >> "$FILE_LIST"
generate_files Reports "Report" "docx" 2000 >> "$FILE_LIST"
generate_files Policies "Policy" "pdf" 1500 >> "$FILE_LIST"
generate_files Invoices "Invoice" "pdf" 1500 >> "$FILE_LIST"
generate_files Memos "Memo" "md" 1500 >> "$FILE_LIST"
generate_files Proposals "Proposal" "pdf" 500 >> "$FILE_LIST"
generate_files Manuals "Manual" "pdf" 500 >> "$FILE_LIST"
generate_files Guidelines "Guideline" "pdf" 500 >> "$FILE_LIST"

ACTUAL_COUNT=$(wc -l < "$FILE_LIST")
log "Generated ${ACTUAL_COUNT} file paths"

# Upload files in parallel
upload_file() {
    curl -s -u "$AUTH" -X PUT "${GF_PATH}/$1" \
        -H "Content-Type: application/octet-stream" \
        -d "MetaVox demo document - $(basename "$1")" > /dev/null 2>&1
}
export -f upload_file
export AUTH GF_PATH

log "Uploading files (10 parallel workers)..."
cat "$FILE_LIST" | xargs -P 10 -I {} bash -c 'upload_file "$@"' _ {}
log "All files uploaded"

# ============================================================
# Step 5: Get file IDs via WebDAV PROPFIND
# ============================================================
log "Step 5: Fetching file IDs via PROPFIND..."

FILE_IDS_MAP="${TMPDIR_SEED}/file_ids.json"

curl -s -u "$AUTH" -X PROPFIND "${GF_PATH}/" \
    -H "Depth: infinity" \
    -H "Content-Type: application/xml" \
    -d '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' \
    > "${TMPDIR_SEED}/propfind.xml"

python3 -c "
import xml.etree.ElementTree as ET
import json, sys

tree = ET.parse('${TMPDIR_SEED}/propfind.xml')
root = tree.getroot()
ns = {'d': 'DAV:', 'oc': 'http://owncloud.org/ns'}

result = {}
for resp in root.findall('.//d:response', ns):
    href = resp.find('d:href', ns).text
    fid_el = resp.find('.//oc:fileid', ns)
    if fid_el is not None and fid_el.text:
        # Extract relative path after the groupfolder path
        parts = href.split('/${GF_NAME}/')
        if len(parts) > 1 and '.' in parts[1]:  # Only files, not dirs
            result[parts[1]] = int(fid_el.text)

with open('${FILE_IDS_MAP}', 'w') as f:
    json.dump(result, f)

print(f'Found {len(result)} file IDs')
" 2>/dev/null

FILE_COUNT=$(python3 -c "import json; print(len(json.load(open('${FILE_IDS_MAP}'))))" 2>/dev/null)
log "Fetched ${FILE_COUNT} file IDs"

# ============================================================
# Step 6: Populate metadata via batch-update API
# ============================================================
log "Step 6: Populating metadata for ${FILE_COUNT} files..."

python3 << 'PYEOF'
import json, subprocess, sys, random, os
from datetime import datetime, timedelta

FILE_IDS_MAP = os.environ.get("FILE_IDS_MAP", "PLACEHOLDER")
OCS_BASE = os.environ.get("OCS_BASE", "PLACEHOLDER")
AUTH = os.environ.get("AUTH", "PLACEHOLDER")
GF_ID = os.environ.get("GF_ID", "PLACEHOLDER")

with open(FILE_IDS_MAP) as f:
    file_ids = json.load(f)

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
           "Apex Global","Summit Partners","Horizon Technologies","Pinnacle Systems","Sterling & Associates",
           "Meridian Group","Atlas Ventures","Nexus Innovations","Quantum Enterprises","Catalyst Partners",
           "Evergreen Solutions","Pacific Rim Holdings","Nordic Digital","Silverline Consulting","Titan Industries",
           "Aurora Biotech","Crossroad Capital","Frontier Analytics","Keystone Health","Lumen Energy"]

COST_CENTERS = ["CC-1000","CC-1100","CC-1200","CC-2000","CC-2100","CC-3000","CC-3100","CC-4000",
                "CC-4100","CC-4200","CC-5000","CC-5100","CC-6000","CC-6100","CC-7000","CC-7100"]

DEPTS = ["Finance","HR","Legal","IT","Marketing","Operations","Sales","R&D","Executive"]
STATUSES = ["Draft","In review","Approved","Published","Archived","Expired"]
PRIORITIES = ["Critical","High","Medium","Low"]
REGIONS = ["North America","Europe","Asia Pacific","Latin America","Middle East","Africa"]
FY = ["FY2023","FY2024","FY2025","FY2026"]
RETENTIONS = ["1 year","3 years","5 years","7 years","10 years","Permanent"]
CLASS_OPTS = ["Internal","External","Confidential","Public","Restricted"]
TAG_OPTS = ["Urgent","Important","Follow-up","Action required","FYI","Template","Compliance"]
APPL_REGS = ["EU","US","UK","APAC","Global"]

def pick(arr): return random.choice(arr)
def pick_n(arr, n): return ";#".join(random.sample(arr, min(n, len(arr))))
def rand_date(start_year=2023, end_year=2027):
    start = datetime(start_year, 1, 1)
    end = datetime(end_year, 12, 31)
    delta = (end - start).days
    return (start + timedelta(days=random.randint(0, delta))).strftime("%Y-%m-%d")

def gen_metadata(path):
    """Generate realistic metadata based on file category."""
    cat = path.split("/")[0] if "/" in path else "Other"

    # Base metadata
    meta = {
        "author": pick(AUTHORS),
        "reviewer": pick(AUTHORS),
        "project_name": pick(PROJECTS),
        "client_name": pick(CLIENTS),
        "cost_center": pick(COST_CENTERS),
        "fiscal_year": pick(FY),
        "region": pick(REGIONS),
        "version_number": str(random.randint(1, 10)),
        "page_count": str(random.randint(1, 200)),
        "review_date": rand_date(2025, 2027),
        "effective_date": rand_date(2023, 2026),
        "expiry_date": rand_date(2025, 2028),
    }

    # Category-specific distributions
    if cat == "Contracts":
        meta["document_type"] = "Contract"
        meta["department"] = pick(["Legal","Finance","Executive","Sales"])
        meta["status"] = pick(["Approved","Published","In review","Expired"])
        meta["priority"] = pick(["Critical","High","High","Medium"])
        meta["retention_period"] = pick(["7 years","10 years","Permanent"])
        meta["classification"] = pick_n(["Confidential","Internal","Restricted"], random.randint(1,2))
        meta["tags"] = pick_n(["Compliance","Important","Action required"], random.randint(1,2))
        meta["applicable_regions"] = pick_n(APPL_REGS, random.randint(1,3))
        meta["budget_amount"] = str(random.randint(5000, 500000))
        meta["reference_number"] = f"CTR-{pick(FY)[-4:]}-{random.randint(1,9999):04d}"
        meta["is_approved"] = pick(["1","1","1","0"])
        meta["is_signed"] = pick(["1","1","0"])
        meta["requires_action"] = pick(["0","0","1"])
    elif cat == "Reports":
        meta["document_type"] = "Report"
        meta["department"] = pick(DEPTS)
        meta["status"] = pick(["Published","Published","Approved","Draft"])
        meta["priority"] = pick(["Medium","Medium","Low","High"])
        meta["retention_period"] = pick(["3 years","5 years","7 years"])
        meta["classification"] = pick_n(["Internal","Public","External"], random.randint(1,2))
        meta["tags"] = pick_n(["FYI","Important","Follow-up"], random.randint(1,2))
        meta["applicable_regions"] = pick_n(APPL_REGS, random.randint(1,2))
        meta["budget_amount"] = str(random.randint(0, 50000))
        meta["reference_number"] = f"RPT-{pick(FY)[-4:]}-{random.randint(1,9999):04d}"
        meta["is_approved"] = pick(["1","1","0"])
        meta["is_signed"] = "0"
        meta["requires_action"] = pick(["0","0","0","1"])
    elif cat == "Policies":
        meta["document_type"] = "Policy"
        meta["department"] = pick(["HR","Legal","IT","Operations","Executive"])
        meta["status"] = pick(["Approved","Published","In review","Draft"])
        meta["priority"] = pick(["High","Medium","Medium","Critical"])
        meta["retention_period"] = pick(["7 years","10 years","Permanent","Permanent"])
        meta["classification"] = pick_n(["Internal","Confidential","Restricted"], random.randint(1,2))
        meta["tags"] = pick_n(["Compliance","Important","Template"], random.randint(1,3))
        meta["applicable_regions"] = pick_n(APPL_REGS, random.randint(2,4))
        meta["budget_amount"] = "0"
        meta["reference_number"] = f"POL-{pick(FY)[-4:]}-{random.randint(1,999):03d}"
        meta["is_approved"] = pick(["1","1","0"])
        meta["is_signed"] = pick(["1","0"])
        meta["requires_action"] = pick(["0","1"])
    elif cat == "Invoices":
        meta["document_type"] = "Invoice"
        meta["department"] = pick(["Finance","Finance","Operations","Sales"])
        meta["status"] = pick(STATUSES)
        meta["priority"] = pick(["Medium","Low","High","Low"])
        meta["retention_period"] = pick(["5 years","7 years","10 years"])
        meta["classification"] = pick_n(["Internal","Confidential"], random.randint(1,2))
        meta["tags"] = pick_n(["Action required","Follow-up","Urgent"], random.randint(0,2))
        meta["applicable_regions"] = pick_n(APPL_REGS, 1)
        meta["budget_amount"] = str(random.randint(100, 250000))
        meta["reference_number"] = f"INV-{pick(FY)[-4:]}-{random.randint(1,99999):05d}"
        meta["is_approved"] = pick(["1","0","1","1"])
        meta["is_signed"] = pick(["1","0"])
        meta["requires_action"] = pick(["1","0","0"])
    elif cat == "Memos":
        meta["document_type"] = "Memo"
        meta["department"] = pick(DEPTS)
        meta["status"] = pick(["Draft","Draft","In review","Approved"])
        meta["priority"] = pick(["Low","Low","Medium","Medium"])
        meta["retention_period"] = pick(["1 year","3 years"])
        meta["classification"] = "Internal"
        meta["tags"] = pick_n(["FYI","Follow-up","Action required"], random.randint(1,2))
        meta["applicable_regions"] = "Global"
        meta["budget_amount"] = "0"
        meta["reference_number"] = f"MEM-{pick(FY)[-4:]}-{random.randint(1,9999):04d}"
        meta["is_approved"] = "0"
        meta["is_signed"] = "0"
        meta["requires_action"] = pick(["1","0"])
        meta["page_count"] = str(random.randint(1, 5))
    elif cat == "Proposals":
        meta["document_type"] = "Proposal"
        meta["department"] = pick(["Sales","Marketing","Executive","R&D"])
        meta["status"] = pick(["Draft","In review","Approved","Published"])
        meta["priority"] = pick(["High","High","Critical","Medium"])
        meta["retention_period"] = pick(["3 years","5 years"])
        meta["classification"] = pick_n(["Confidential","Internal","External"], random.randint(1,2))
        meta["tags"] = pick_n(["Important","Action required","Urgent"], random.randint(1,2))
        meta["applicable_regions"] = pick_n(APPL_REGS, random.randint(1,3))
        meta["budget_amount"] = str(random.randint(10000, 1000000))
        meta["reference_number"] = f"PRP-{pick(FY)[-4:]}-{random.randint(1,999):03d}"
        meta["is_approved"] = pick(["0","0","1"])
        meta["is_signed"] = "0"
        meta["requires_action"] = pick(["1","1","0"])
    elif cat == "Manuals":
        meta["document_type"] = "Manual"
        meta["department"] = pick(["IT","HR","Operations"])
        meta["status"] = pick(["Published","Published","Approved"])
        meta["priority"] = pick(["Medium","Low"])
        meta["retention_period"] = pick(["Permanent","10 years"])
        meta["classification"] = pick_n(["Internal","Public"], random.randint(1,2))
        meta["tags"] = pick_n(["Template","FYI","Compliance"], random.randint(1,2))
        meta["applicable_regions"] = "Global"
        meta["budget_amount"] = "0"
        meta["reference_number"] = f"MAN-{pick(FY)[-4:]}-{random.randint(1,999):03d}"
        meta["is_approved"] = "1"
        meta["is_signed"] = "0"
        meta["requires_action"] = "0"
        meta["page_count"] = str(random.randint(20, 300))
    else:  # Guidelines
        meta["document_type"] = "Guideline"
        meta["department"] = pick(DEPTS)
        meta["status"] = pick(["Approved","Published","In review"])
        meta["priority"] = pick(["Medium","Low","High"])
        meta["retention_period"] = pick(["Permanent","10 years","7 years"])
        meta["classification"] = pick_n(["Internal","Public","External"], random.randint(1,2))
        meta["tags"] = pick_n(["Compliance","Template","Important"], random.randint(1,3))
        meta["applicable_regions"] = pick_n(APPL_REGS, random.randint(2,4))
        meta["budget_amount"] = "0"
        meta["reference_number"] = f"GDL-{pick(FY)[-4:]}-{random.randint(1,999):03d}"
        meta["is_approved"] = pick(["1","1","0"])
        meta["is_signed"] = "0"
        meta["requires_action"] = pick(["0","0","1"])

    return meta

# Process in batches
items = list(file_ids.items())
total = len(items)
batch_num = 0

for i in range(0, total, 100):
    batch = items[i:i+100]
    updates = []
    for path, fid in batch:
        meta = gen_metadata(path)
        updates.append({"file_id": fid, "metadata": meta})

    payload = json.dumps({"updates": updates})

    import urllib.request
    url = f"{OCS_BASE}/groupfolders/{GF_ID}/files/metadata/batch-update?format=json"

    # Use curl for auth simplicity
    import subprocess
    result = subprocess.run(
        ["curl", "-s", "-u", AUTH, "-H", "OCS-APIREQUEST: true",
         "-H", "Content-Type: application/json", "-X", "POST", url, "-d", payload],
        capture_output=True, text=True
    )

    batch_num += 1
    done = min(i + 100, total)
    print(f"  Batch {batch_num}: {done}/{total} files updated", flush=True)

print(f"Metadata populated for {total} files")
PYEOF

log "Metadata population complete"

# ============================================================
# Step 7: Create 30 views via API
# ============================================================
log "Step 7: Creating 30 views..."

# Helper to build column JSON
cols() {
    python3 -c "
import json
fields = '$1'.split(',')
field_ids = {$(for k in "${!FIELD_IDS[@]}"; do echo "'$k': ${FIELD_IDS[$k]},"; done)}
cols = []
for f in fields:
    f = f.strip()
    if f in field_ids:
        cols.append({'field_id': field_ids[f], 'visible': True, 'filterable': True})
print(json.dumps(cols))
"
}

cv() {
    local name="$1" columns="$2" sort_field="$3" sort_order="$4" filters="${5:-}"
    local cols_json=$(cols "$columns")
    local filters_json="${filters:-{}}"
    local payload=$(python3 -c "
import json
print(json.dumps({
    'name': '${name}',
    'columns': ${cols_json},
    'filters': ${filters_json},
    'sort_field': '${sort_field}',
    'sort_order': '${sort_order}'
}))
")
    local resp=$(ocs_post "${OCS_BASE}/groupfolders/${GF_ID}/views" "$payload")
    local vid=$(echo "$resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['ocs']['data']['id'])" 2>/dev/null)
    echo "  Created view: ${name} (id=${vid})"
}

# Document type filter helper
tf() {
    local field="$1" values="$2"
    python3 -c "
import json
fid = '${FIELD_IDS[$field]:-0}'
print(json.dumps({fid: '$values'.split(',')}))"
}

cv "All documents" "document_type,department,status,author,priority,region" "author" "asc"
cv "By department" "department,document_type,status,author,cost_center" "department" "asc"
cv "By status" "status,document_type,department,author,priority" "status" "asc"
cv "Contracts" "document_type,department,author,client_name,classification,expiry_date,is_signed" "author" "asc" "$(tf document_type Contract)"
cv "Reports" "document_type,department,author,fiscal_year,region,page_count" "fiscal_year" "desc" "$(tf document_type Report)"
cv "Policies & guidelines" "document_type,department,status,version_number,retention_period,is_approved" "department" "asc" "$(tf document_type Policy,Guideline)"
cv "Invoices" "document_type,department,author,budget_amount,fiscal_year,reference_number" "budget_amount" "desc" "$(tf document_type Invoice)"
cv "Draft documents" "status,document_type,department,author,priority,requires_action" "priority" "asc" "$(tf status Draft)"
cv "Approved & published" "status,document_type,department,author,is_approved,effective_date" "department" "asc" "$(tf status Approved,Published)"
cv "Archived" "status,document_type,department,author,expiry_date,retention_period" "expiry_date" "desc" "$(tf status Archived)"
cv "Expired items" "status,document_type,expiry_date,department,requires_action" "expiry_date" "asc" "$(tf status Expired)"
cv "High priority" "priority,document_type,department,author,status,requires_action" "document_type" "asc" "$(tf priority Critical,High)"
cv "Finance department" "department,document_type,status,budget_amount,fiscal_year,cost_center" "budget_amount" "desc" "$(tf department Finance)"
cv "Legal department" "department,document_type,status,author,classification,is_signed" "document_type" "asc" "$(tf department Legal)"
cv "IT department" "department,document_type,status,author,tags,project_name" "author" "asc" "$(tf department IT)"
cv "HR department" "department,document_type,status,author,version_number,retention_period" "status" "asc" "$(tf department HR)"
cv "Marketing & Sales" "department,document_type,author,client_name,tags,region" "author" "asc" "$(tf department Marketing,Sales)"
cv "Executive overview" "department,document_type,status,priority,budget_amount,region" "priority" "asc" "$(tf department Executive)"
cv "Confidential files" "classification,document_type,department,author,is_signed,retention_period" "department" "asc" "$(tf classification Confidential)"
cv "Public documents" "classification,document_type,department,author,applicable_regions" "document_type" "asc" "$(tf classification Public)"
cv "Needs review" "status,review_date,document_type,department,reviewer,priority" "review_date" "asc" "$(tf status 'In review')"
cv "Upcoming reviews" "review_date,document_type,department,author,status" "review_date" "asc"
cv "By project" "project_name,document_type,department,author,client_name,budget_amount" "project_name" "asc"
cv "Action required" "requires_action,tags,document_type,department,author,priority" "priority" "asc" "$(tf requires_action 1)"
cv "Compliance docs" "tags,document_type,department,classification,retention_period,applicable_regions" "department" "asc" "$(tf tags Compliance)"
cv "Templates" "tags,document_type,department,version_number,page_count" "document_type" "asc" "$(tf tags Template)"
cv "Not yet approved" "is_approved,document_type,department,author,reviewer,status" "department" "asc" "$(tf is_approved 0)"
cv "Budget overview" "budget_amount,department,document_type,project_name,client_name,fiscal_year" "budget_amount" "desc"
cv "Full metadata" "document_type,department,status,priority,region,fiscal_year,retention_period,author,reviewer,project_name,client_name,cost_center,reference_number,classification,tags,applicable_regions,review_date,effective_date,expiry_date,version_number,budget_amount,page_count,is_approved,is_signed,requires_action" "author" "asc"
cv "EU regulations" "applicable_regions,document_type,department,classification,retention_period,effective_date" "effective_date" "desc" "$(tf applicable_regions EU)"

# ============================================================
# Cleanup & Summary
# ============================================================
rm -rf "$TMPDIR_SEED"

log "============================================"
log "Demo environment setup complete!"
log "============================================"
log "Groupfolder: ${GF_NAME} (ID: ${GF_ID})"
log "Fields: 25 metadata fields"
log "Files: ${TOTAL_FILES} documents"
log "Views: 30 custom views"
log "URL: ${NC_URL}/apps/files/?dir=/${GF_NAME}"
log "============================================"
