"""
Configuration file for LLMR Generator
Customize settings for your specific website needs
"""

# ============================================================================
# EMBEDDING CONFIGURATION
# ============================================================================

# Choose embedding method: "hash" (default), "sentence-transformers", "openai", "cohere"
EMBEDDING_METHOD = "hash"

# Embedding dimensions (reduce for smaller file size)
EMBEDDING_DIMENSIONS = 16

# API keys (if using cloud embeddings)
OPENAI_API_KEY = ""
COHERE_API_KEY = ""

# Model selection
SENTENCE_TRANSFORMER_MODEL = "all-MiniLM-L6-v2"  # Fast, good quality
# Alternatives:
# "all-mpnet-base-v2"  # Higher quality, slower
# "paraphrase-multilingual-MiniLM-L12-v2"  # Multilingual support

# ============================================================================
# CONTENT FILTERING
# ============================================================================

# Skip these file patterns
SKIP_PATTERNS = [
    "**/node_modules/**",
    "**/vendor/**",
    "**/tests/**",
    "**/test/**",
    "**/.git/**",
    "**/admin/**",
    "**/private/**"
]

# Skip files with these names
SKIP_FILES = [
    "404.html",
    "500.html",
    "error.html",
    "test.html"
]

# Only process files matching these patterns (empty = process all)
INCLUDE_PATTERNS = [
    # "**/*blog*.html",
    # "**/*article*.html",
]

# ============================================================================
# CONTENT TYPE DETECTION
# ============================================================================

# Add custom content type detection rules
CUSTOM_CONTENT_TYPES = {
    "Documentation": ["docs", "documentation", "manual", "reference"],
    "Portfolio": ["portfolio", "project", "work", "showcase"],
    "Service": ["service", "offering", "solution"],
    # Add your own types here
}

# Override automatic detection for specific URLs
URL_TYPE_OVERRIDES = {
    "/about.html": "AboutPage",
    "/contact.html": "ContactPage",
    "/privacy.html": "WebPage",
    # Add specific overrides here
}

# ============================================================================
# KEYWORD EXTRACTION
# ============================================================================

# Maximum keywords per page
MAX_KEYWORDS = 10

# Custom stop words (in addition to defaults)
CUSTOM_STOP_WORDS = [
    # Add domain-specific words to ignore
    # "website", "page", "click", "here"
]

# Minimum word frequency to be considered a keyword
MIN_KEYWORD_FREQUENCY = 2

# ============================================================================
# CONTENT PROCESSING
# ============================================================================

# Maximum description length (characters)
MAX_DESCRIPTION_LENGTH = 200

# Maximum title length (characters)
MAX_TITLE_LENGTH = 100

# Number of paragraphs to include in embedding
PARAGRAPHS_FOR_EMBEDDING = 5

# Read time calculation (words per minute)
WORDS_PER_MINUTE = 200

# ============================================================================
# OUTPUT CONFIGURATION
# ============================================================================

# Output filename
OUTPUT_FILENAME = "site.llmr"

# Pretty print JSON (set to None for compact output)
JSON_INDENT = 2

# Include full uncompressed data (for debugging)
INCLUDE_DEBUG_DATA = False

# Compression level: "minimal", "standard", "aggressive"
COMPRESSION_LEVEL = "standard"

# ============================================================================
# SITE METADATA
# ============================================================================

# Override site metadata (leave empty to auto-detect)
SITE_TITLE = ""
SITE_DESCRIPTION = ""
SITE_AUTHOR = ""
SITE_BASE_URL = ""

# Additional site metadata
SITE_METADATA = {
    # "organization": "Your Company",
    # "industry": "Technology",
    # "founded": "2020",
}

# ============================================================================
# ADVANCED OPTIONS
# ============================================================================

# Enable verbose logging
VERBOSE = True

# Include raw JSON-LD data in output
INCLUDE_JSON_LD = True

# Include microdata in output
INCLUDE_MICRODATA = False

# Calculate content quality scores
CALCULATE_QUALITY_SCORES = False

# Include image analysis
ANALYZE_IMAGES = False

# Include link analysis
ANALYZE_LINKS = False

# ============================================================================
# PERFORMANCE
# ============================================================================

# Number of pages to process in parallel (1 = no parallelization)
PARALLEL_WORKERS = 1

# Cache parsed pages
ENABLE_CACHING = False

# ============================================================================
# VALIDATION
# ============================================================================

# Validate generated LLMR file
VALIDATE_OUTPUT = True

# Minimum required fields for each page
REQUIRED_FIELDS = ["id", "u", "t", "ti"]

# Warn if pages are missing these fields
RECOMMENDED_FIELDS = ["d", "kw", "emb"]

# ============================================================================
# EXAMPLE USAGE
# ============================================================================

"""
To use this configuration:

1. Save as llmr_config.py in the same directory as generate_llmr.py

2. Import in your script:
   from llmr_config import *

3. Or pass as argument:
   python generate_llmr.py /path/to/site --config llmr_config.py

4. Or set environment variables:
   export LLMR_EMBEDDING_METHOD=sentence-transformers
   python generate_llmr.py /path/to/site
"""
