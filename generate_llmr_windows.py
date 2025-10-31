#!/usr/bin/env python3
"""
Windows-Compatible LLMR Generator Wrapper
Forces UTF-8 encoding for Windows systems
"""

import sys
import os

# Force UTF-8 encoding on Windows
if sys.platform == "win32":
    # Set environment variable for Python to use UTF-8
    os.environ["PYTHONIOENCODING"] = "utf-8"
    
    # Try to reconfigure stdout/stderr to UTF-8
    try:
        import io
        sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
        sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')
    except Exception as e:
        print(f"[WARNING] Could not set UTF-8 encoding: {e}")
        print("[INFO] Using ASCII-safe output mode")

# Now import and run the main script
if __name__ == "__main__":
    # Import the main generator
    import generate_llmr as generator
    
    # Run it
    generator.main()
