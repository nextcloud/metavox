#!/bin/bash
# Push to GitHub excluding internal documents
#
# This script pushes to GitHub while excluding files listed in .gitignore-github
# Use this instead of `git push github` to keep internal docs private
#
# Usage: ./push-to-github.sh [branch]

BRANCH=${1:-main}

echo "🔒 Pushing to GitHub (excluding internal docs)..."

# Stash current changes if any
git stash -q --include-untracked 2>/dev/null

# Create a temporary branch for GitHub push
TEMP_BRANCH="github-push-temp-$$"
git checkout -b $TEMP_BRANCH

# Remove files that should not go to GitHub
while IFS= read -r file; do
    # Skip comments and empty lines
    [[ "$file" =~ ^#.*$ ]] && continue
    [[ -z "$file" ]] && continue

    if [ -f "$file" ]; then
        git rm --cached "$file" 2>/dev/null
        echo "  Excluding: $file"
    fi
done < .gitignore-github

# Commit the removal (if any changes)
git diff --cached --quiet || git commit -m "temp: exclude internal docs for github"

# Push to GitHub
git push github $TEMP_BRANCH:$BRANCH

# Go back to original branch
git checkout -
git branch -D $TEMP_BRANCH

# Restore stashed changes
git stash pop -q 2>/dev/null

echo "✅ Done! Pushed to GitHub without internal docs."
