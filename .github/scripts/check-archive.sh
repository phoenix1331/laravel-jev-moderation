#!/usr/bin/env sh
# Fails when the Composer dist archive would ship anything outside the allowed list.
# Keep in step with the export-ignore rules in .gitattributes.

ALLOWED='^(LICENSE|README\.md|composer\.json|(src|resources|config|database)(/.*)?)$'

unexpected=$(git archive HEAD | tar -t | grep -vE "$ALLOWED")

if [ -n "$unexpected" ]; then
  echo "error: the dist archive contains files outside the allowed list:"
  echo "$unexpected"
  echo "add them to .gitattributes with export-ignore, or to ALLOWED in this script if they must ship."
  exit 1
fi

echo "archive contents:"
git archive HEAD | tar -t
