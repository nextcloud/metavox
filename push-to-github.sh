#!/bin/bash
# Push to GitHub excluding internal documents and deploy scripts
#
# Files listed in .gitignore-github are removed before pushing.
# Use this instead of `git push github` to keep internal files private.
#
# Usage: ./push-to-github.sh [branch]

set -e

BRANCH=${1:-main}

echo "🔒 Pushing $BRANCH to GitHub (excluding internal files)..."

# Stash current changes if any
STASHED=false
if ! git diff --quiet 2>/dev/null || ! git diff --cached --quiet 2>/dev/null; then
    git stash -q --include-untracked 2>/dev/null && STASHED=true
fi

# Create a temporary branch for GitHub push
TEMP_BRANCH="github-push-temp-$$"
git checkout -b "$TEMP_BRANCH" -q

# Remove files/directories listed in .gitignore-github
# tr -d '\r' strips Windows line endings
while IFS= read -r pattern; do
    pattern=$(echo "$pattern" | tr -d '\r')
    [[ "$pattern" =~ ^#.*$ ]] && continue
    [[ -z "${pattern// }" ]] && continue

    CLEAN="${pattern%/}"
    if git rm -rf "$CLEAN" -q 2>/dev/null; then
        echo "  Excluded: $CLEAN"
    fi
done < .gitignore-github

# Commit the removal if anything changed
if ! git diff --cached --quiet 2>/dev/null; then
    git commit -q -m "temp: exclude internal files for GitHub push"
fi

# Push to GitHub
git push github "$TEMP_BRANCH:$BRANCH" --force-with-lease
echo "  Pushed to github/$BRANCH"

# Go back to original branch and clean up
git checkout "$BRANCH" -q
git branch -D "$TEMP_BRANCH" -q

# Restore stashed changes
if [ "$STASHED" = true ]; then
    git stash pop -q 2>/dev/null || true
fi

echo "✅ Done! Pushed $BRANCH to GitHub without internal files."
