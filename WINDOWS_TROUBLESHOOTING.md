# Windows Troubleshooting Guide

## Issue: Unicode Encoding Error

### Problem
```
UnicodeEncodeError: 'charmap' codec can't encode character '\u2713'
```

### Cause
Windows uses `cp1252` encoding by default, which doesn't support Unicode characters like ✓ and ✗.

### Solutions (3 options)

#### Option 1: Use the Fixed Script (Recommended)
The main script has been updated to use ASCII-safe characters.

```bash
# Just run the updated script
python generate_llmr.py
```

#### Option 2: Use the Windows Wrapper
Use the special Windows wrapper that forces UTF-8 encoding:

```bash
python generate_llmr_windows.py /path/to/website
```

#### Option 3: Set Windows to Use UTF-8
Set environment variable before running:

**PowerShell:**
```powershell
$env:PYTHONIOENCODING="utf-8"
python generate_llmr.py
```

**CMD:**
```cmd
set PYTHONIOENCODING=utf-8
python generate_llmr.py
```

**Git Bash:**
```bash
export PYTHONIOENCODING=utf-8
python3 generate_llmr.py
```

---

## Debugging Steps

### Step 1: Check Your Directory
Make sure you're in the right place:

```bash
# List HTML files
ls *.html

# Or in PowerShell
Get-ChildItem -Filter *.html

# Or search recursively
find . -name "*.html"  # Git Bash
Get-ChildItem -Recurse -Filter *.html  # PowerShell
```

### Step 2: Run the Debug Tool
This will test your website and show what's happening:

```bash
python debug_llmr.py .
```

Or specify a path:
```bash
python debug_llmr.py F:/raphaelreck
```

### Step 3: Check the Output
The debug tool will show:
- How many HTML files were found
- If they can be parsed
- What content type they are
- Any errors

---

## Common Issues

### Issue 1: No HTML files found
**Symptoms:**
```
Found 0 HTML files
```

**Solutions:**
1. Check you're in the correct directory
2. Ensure files have `.html` extension (not `.htm`)
3. Check file permissions

### Issue 2: Can't read HTML files
**Symptoms:**
```
Error processing file: [Errno 2] No such file or directory
```

**Solutions:**
1. Use absolute path: `python generate_llmr.py "F:/raphaelreck"`
2. Check file permissions
3. Ensure no files are locked by other programs

### Issue 3: Encoding errors in HTML content
**Symptoms:**
```
UnicodeDecodeError: 'utf-8' codec can't decode
```

**Solutions:**
1. Check HTML file encoding (should be UTF-8)
2. Script will try to handle this automatically
3. Convert files to UTF-8 if needed

### Issue 4: Permission denied
**Symptoms:**
```
PermissionError: [Errno 13] Permission denied
```

**Solutions:**
1. Run terminal as Administrator
2. Check file/folder permissions
3. Close any programs that might have files open

---

## Quick Test

Run this to verify everything works:

```bash
# 1. Check Python version (need 3.7+)
python --version

# 2. Run debug tool
python debug_llmr.py .

# 3. If debug passes, run generator
python generate_llmr.py .
```

---

## Your Specific Case

Based on your error, here's what to do:

```bash
# In Git Bash at F:/raphaelreck
cd /f/raphaelreck

# Set UTF-8 encoding
export PYTHONIOENCODING=utf-8

# Run the updated script (Unicode characters removed)
python3 generate_llmr.py

# OR use the Windows wrapper
python3 generate_llmr_windows.py

# OR run debug first
python3 debug_llmr.py .
```

---

## Expected Output (Success)

```
============================================================
Universal LLM-Readable Format Generator v2.0
============================================================

Found 13 HTML files
  [OK] Processed: index.html
  [OK] Processed: about.html
  [OK] Processed: blog-post-1.html
  ...

Found 13 pages:
  Content types: {'Article': 8, 'WebPage': 5}

============================================================
[SUCCESS] Successfully generated LLMR file!
============================================================

Output: F:/raphaelreck/site.llmr

Statistics:
  total_pages: 13
  total_words: 15000
  ...
```

---

## Still Having Issues?

1. **Run the debug tool first:**
   ```bash
   python debug_llmr.py .
   ```

2. **Check the output** - it will tell you exactly what's wrong

3. **Try with a single HTML file:**
   ```bash
   # Create a test directory
   mkdir test
   cp index.html test/
   python generate_llmr.py test/
   ```

4. **Share the debug output** if you need more help

---

## Files You Need

1. **generate_llmr.py** - Main script (updated for Windows)
2. **generate_llmr_windows.py** - Windows wrapper (UTF-8 helper)
3. **debug_llmr.py** - Debug tool (diagnose issues)

All three files are included in your package and are now Windows-compatible!

---

## Prevention

To avoid encoding issues in future:

1. **Set UTF-8 globally** (Windows 10 1903+):
   - Settings → Time & Language → Language
   - Administrative language settings → Change system locale
   - Check "Beta: Use Unicode UTF-8 for worldwide language support"
   - Restart

2. **Or use the wrapper script** - it handles encoding automatically

3. **Or set environment variable** in your shell profile
