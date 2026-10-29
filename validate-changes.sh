#!/bin/bash
# Simple syntax validation for PHP files

echo "Checking PHP syntax for modified/created files..."

files=(
    "web/modules/custom/fresco_pages/src/Service/PublicationManager.php"
    "web/modules/custom/fresco_pages/tests/src/Kernel/PublicationWorkflowTest.php"
    "web/modules/custom/fresco_pages/tests/src/Functional/PublicationAccessControlTest.php"
)

for file in "${files[@]}"; do
    if php -l "$file" 2>/dev/null | grep -q "No syntax errors"; then
        echo "✓ $file"
    else
        echo "✗ $file - syntax error"
        php -l "$file"
        exit 1
    fi
done

echo ""
echo "Checking YAML syntax for services file..."
if which yaml-lint > /dev/null 2>&1; then
    if yaml-lint web/modules/custom/fresco_pages/fresco_pages.services.yml > /dev/null 2>&1; then
        echo "✓ fresco_pages.services.yml"
    else
        echo "✗ fresco_pages.services.yml - YAML syntax error"
        exit 1
    fi
else
    echo "⚠ yaml-lint not available, skipping YAML check"
fi

echo ""
echo "All syntax checks passed!"
