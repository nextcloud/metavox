#!/bin/bash

# MetaVox Deployment Script
# Deploys to Nextcloud test server

set -e

# Configuration
APP_NAME="metavox"
REMOTE_USER="rdekker"
REMOTE_PATH="/var/www/nextcloud/apps"
SSH_KEY="~/.ssh/sur"
LOCAL_PATH="$(pwd)"

# Always deploy to 3dev
REMOTE_HOST="145.38.188.218"
SERVER_NAME="3dev"

# Extract version from appinfo/info.xml
VERSION=$(grep '<version>' appinfo/info.xml | head -1 | sed 's/.*<version>\([^<]*\)<\/version>.*/\1/')

echo "🚀 MetaVox Deployment Script"
echo "=============================="
echo "📌 Version: $VERSION"
echo "📅 Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo "🖥️  Server: $SERVER_NAME ($REMOTE_HOST)"

# Files and folders to include in deployment
INCLUDE_ITEMS=(
    "appinfo"
    "lib"
    "l10n"
    "templates"
    "css"
    "img"
    "js"
    "CHANGELOG.md"
    "LICENSE"
    "README.md"
)

echo ""
echo "📦 Step 1: Building frontend..."

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
    echo "  📥 Installing dependencies..."
    npm install
fi

# Build
npm run build

if [ $? -ne 0 ]; then
    echo "❌ Build failed!"
    exit 1
fi

echo "✅ Build completed"

echo ""
echo "📋 Step 2: Creating deployment package..."

# Create temporary directory
TEMP_DIR=$(mktemp -d)
DEPLOY_DIR="$TEMP_DIR/$APP_NAME"
mkdir -p "$DEPLOY_DIR"

# Copy files
for item in "${INCLUDE_ITEMS[@]}"; do
    if [ -e "$LOCAL_PATH/$item" ]; then
        echo "  📄 Copying $item..."
        cp -r "$LOCAL_PATH/$item" "$DEPLOY_DIR/"
    else
        echo "  ⚠️  Warning: $item not found, skipping..."
    fi
done

# Create tarball
TARBALL="$TEMP_DIR/${APP_NAME}.tar.gz"
echo "  📦 Creating tarball..."
cd "$TEMP_DIR"
tar -czf "$TARBALL" "$APP_NAME"

echo "✅ Deployment package created"

echo ""
echo "🚢 Step 3: Deploying to server..."
echo "  Server: $REMOTE_HOST"
echo "  Path: $REMOTE_PATH/$APP_NAME"

# Upload tarball
echo "  📤 Uploading package..."
scp -i "$SSH_KEY" "$TARBALL" "${REMOTE_USER}@${REMOTE_HOST}:/tmp/${APP_NAME}.tar.gz"

# Extract and setup on server
echo "  📂 Extracting on server..."
ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << EOF
    set -e

    # Navigate to apps directory
    cd $REMOTE_PATH

    # Backup existing installation if present
    if [ -d "$APP_NAME" ]; then
        echo "  💾 Backing up existing installation..."
        BACKUP_NAME="${APP_NAME}.backup.\$(date +%Y%m%d_%H%M%S)"
        sudo mv $APP_NAME "/tmp/\$BACKUP_NAME" || true
        echo "  📦 Backup saved to /tmp/\$BACKUP_NAME"
    fi

    # Extract new version
    echo "  📦 Extracting new version..."
    sudo tar -xzf /tmp/${APP_NAME}.tar.gz -C $REMOTE_PATH

    # Set permissions
    echo "  🔐 Setting permissions..."
    sudo chown -R www-data:www-data $REMOTE_PATH/$APP_NAME
    sudo chmod -R 755 $REMOTE_PATH/$APP_NAME

    # Clean up
    rm /tmp/${APP_NAME}.tar.gz

    echo "  ✅ Files deployed"
EOF

echo ""
echo "🔧 Step 4: Enabling app..."
ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << EOF
    set -e
    cd /var/www/nextcloud

    # Enable app
    echo "  🔌 Enabling app..."
    sudo -u www-data php occ app:enable $APP_NAME || true

    echo "  ✅ App enabled"
EOF

# Verify deployed version
echo ""
echo "🔍 Step 5: Verifying deployed version..."
DEPLOYED_VERSION=$(ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "grep '<version>' $REMOTE_PATH/$APP_NAME/appinfo/info.xml | sed 's/.*<version>\([^<]*\)<\/version>.*/\1/'")
echo "  📌 Deployed version: $DEPLOYED_VERSION"

if [ "$VERSION" = "$DEPLOYED_VERSION" ]; then
    echo "  ✅ Version matches!"
else
    echo "  ⚠️  Version mismatch! Local: $VERSION, Deployed: $DEPLOYED_VERSION"
fi

# Cleanup local temp files
rm -rf "$TEMP_DIR"

echo ""
echo "✅ Deployment completed successfully!"
echo ""
echo "📊 Summary:"
echo "  • App Name: $APP_NAME"
echo "  • Version: $DEPLOYED_VERSION"
echo "  • Server: $SERVER_NAME ($REMOTE_HOST)"
echo "  • Status: Deployed and enabled"
echo ""
echo "🔄 Rollback (if needed):"
echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'ls -la /tmp/${APP_NAME}.backup.*'"
echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'sudo rm -rf $REMOTE_PATH/$APP_NAME && sudo mv /tmp/${APP_NAME}.backup.YYYYMMDD_HHMMSS $REMOTE_PATH/$APP_NAME'"
echo ""
echo "📝 View logs:"
echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'sudo tail -f /var/www/nextcloud/data/nextcloud.log'"
echo ""
