#!/usr/bin/env python3
"""
Production Embedding Integration for LLMR Generator
Examples of integrating real embedding models
"""

import sys
from typing import List, Optional
from pathlib import Path

# Try to import various embedding libraries
try:
    from sentence_transformers import SentenceTransformer
    HAS_SENTENCE_TRANSFORMERS = True
except ImportError:
    HAS_SENTENCE_TRANSFORMERS = False

try:
    import openai
    HAS_OPENAI = True
except ImportError:
    HAS_OPENAI = False

try:
    import cohere
    HAS_COHERE = True
except ImportError:
    HAS_COHERE = False

try:
    import anthropic
    HAS_ANTHROPIC = True
except ImportError:
    HAS_ANTHROPIC = False


class EmbeddingProviderFactory:
    """Factory to create embedding providers based on availability and preference"""
    
    @staticmethod
    def create(provider: str = "auto", **kwargs):
        """
        Create an embedding provider
        
        Args:
            provider: "auto", "sentence-transformers", "openai", "cohere", "anthropic", "hash"
            **kwargs: Provider-specific arguments (api_key, model, etc.)
        """
        if provider == "auto":
            # Auto-detect best available provider
            if HAS_SENTENCE_TRANSFORMERS:
                return SentenceTransformerEmbeddings(**kwargs)
            elif HAS_OPENAI and kwargs.get("openai_api_key"):
                return OpenAIEmbeddings(**kwargs)
            elif HAS_COHERE and kwargs.get("cohere_api_key"):
                return CohereEmbeddings(**kwargs)
            else:
                print("[WARNING] No embedding library found. Using fallback hash-based embeddings.")
                print("    For production, install: pip install sentence-transformers")
                return HashEmbeddings(**kwargs)
        
        elif provider == "sentence-transformers":
            if not HAS_SENTENCE_TRANSFORMERS:
                raise ImportError("sentence-transformers not installed. Run: pip install sentence-transformers")
            return SentenceTransformerEmbeddings(**kwargs)
        
        elif provider == "openai":
            if not HAS_OPENAI:
                raise ImportError("openai not installed. Run: pip install openai")
            return OpenAIEmbeddings(**kwargs)
        
        elif provider == "cohere":
            if not HAS_COHERE:
                raise ImportError("cohere not installed. Run: pip install cohere")
            return CohereEmbeddings(**kwargs)
        
        elif provider == "anthropic":
            if not HAS_ANTHROPIC:
                raise ImportError("anthropic not installed. Run: pip install anthropic")
            return AnthropicEmbeddings(**kwargs)
        
        elif provider == "hash":
            return HashEmbeddings(**kwargs)
        
        else:
            raise ValueError(f"Unknown provider: {provider}")


class SentenceTransformerEmbeddings:
    """
    Local embeddings using sentence-transformers
    
    Pros:
    - Free and unlimited
    - Fast inference
    - No API calls
    - Good quality
    - Privacy (runs locally)
    
    Cons:
    - Requires GPU for best performance
    - Model download required (~90MB)
    - Memory usage
    """
    
    def __init__(self, model: str = "all-MiniLM-L6-v2", device: str = "cpu", **kwargs):
        """
        Initialize sentence transformer
        
        Popular models:
        - all-MiniLM-L6-v2: Fast, good quality (384D)
        - all-mpnet-base-v2: Higher quality, slower (768D)
        - paraphrase-multilingual-MiniLM-L12-v2: Multilingual (384D)
        """
        print(f"Loading sentence-transformers model: {model}")
        self.model = SentenceTransformer(model, device=device)
        self.dimensions = self.model.get_sentence_embedding_dimension()
        print(f"[OK] Loaded {model} ({self.dimensions} dimensions)")
    
    def embed(self, text: str) -> List[float]:
        """Generate embedding for text"""
        if not text:
            return [0.0] * self.dimensions
        
        # Truncate very long text
        text = text[:8000]
        
        embedding = self.model.encode(text, convert_to_numpy=True)
        return embedding.tolist()
    
    def embed_batch(self, texts: List[str], batch_size: int = 32) -> List[List[float]]:
        """Generate embeddings for multiple texts (faster)"""
        if not texts:
            return []
        
        # Truncate long texts
        texts = [t[:8000] for t in texts]
        
        embeddings = self.model.encode(texts, batch_size=batch_size, 
                                       show_progress_bar=True,
                                       convert_to_numpy=True)
        return embeddings.tolist()


class OpenAIEmbeddings:
    """
    OpenAI embeddings via API
    
    Pros:
    - State-of-the-art quality
    - No local resources needed
    - Latest models
    
    Cons:
    - Costs money (~$0.0001 per 1K tokens)
    - Requires API key
    - Network latency
    - Rate limits
    """
    
    def __init__(self, api_key: str = None, model: str = "text-embedding-3-small", **kwargs):
        """
        Initialize OpenAI embeddings
        
        Models:
        - text-embedding-3-small: Fast, cheap, 1536D
        - text-embedding-3-large: Best quality, 3072D
        - text-embedding-ada-002: Legacy, 1536D
        """
        if api_key:
            openai.api_key = api_key
        
        self.model = model
        self.dimensions = 1536 if "small" in model or "ada" in model else 3072
        print(f"[OK] Using OpenAI embeddings: {model} ({self.dimensions} dimensions)")
    
    def embed(self, text: str) -> List[float]:
        """Generate embedding for text"""
        if not text:
            return [0.0] * self.dimensions
        
        text = text[:8000]
        
        try:
            response = openai.embeddings.create(
                input=text,
                model=self.model
            )
            return response.data[0].embedding
        except Exception as e:
            print(f"[WARNING] OpenAI API error: {e}")
            return [0.0] * self.dimensions
    
    def embed_batch(self, texts: List[str], batch_size: int = 100) -> List[List[float]]:
        """Generate embeddings for multiple texts"""
        if not texts:
            return []
        
        texts = [t[:8000] for t in texts]
        embeddings = []
        
        # Process in batches
        for i in range(0, len(texts), batch_size):
            batch = texts[i:i + batch_size]
            try:
                response = openai.embeddings.create(
                    input=batch,
                    model=self.model
                )
                embeddings.extend([item.embedding for item in response.data])
            except Exception as e:
                print(f"[WARNING] OpenAI API error for batch {i}: {e}")
                # Add zero embeddings for failed batch
                embeddings.extend([[0.0] * self.dimensions] * len(batch))
        
        return embeddings


class CohereEmbeddings:
    """
    Cohere embeddings via API
    
    Pros:
    - Excellent quality
    - Multilingual support
    - Specialized models
    
    Cons:
    - Costs money
    - Requires API key
    - Rate limits
    """
    
    def __init__(self, api_key: str = None, model: str = "embed-english-v3.0", **kwargs):
        """
        Initialize Cohere embeddings
        
        Models:
        - embed-english-v3.0: Best for English, 1024D
        - embed-multilingual-v3.0: 90+ languages, 1024D
        - embed-english-light-v3.0: Faster, 384D
        """
        if not api_key:
            raise ValueError("Cohere API key required")
        
        self.co = cohere.Client(api_key)
        self.model = model
        self.dimensions = 1024 if "light" not in model else 384
        print(f"[OK] Using Cohere embeddings: {model} ({self.dimensions} dimensions)")
    
    def embed(self, text: str) -> List[float]:
        """Generate embedding for text"""
        if not text:
            return [0.0] * self.dimensions
        
        text = text[:8000]
        
        try:
            response = self.co.embed(
                texts=[text],
                model=self.model,
                input_type="search_document"
            )
            return response.embeddings[0]
        except Exception as e:
            print(f"[WARNING] Cohere API error: {e}")
            return [0.0] * self.dimensions
    
    def embed_batch(self, texts: List[str], batch_size: int = 96) -> List[List[float]]:
        """Generate embeddings for multiple texts"""
        if not texts:
            return []
        
        texts = [t[:8000] for t in texts]
        embeddings = []
        
        for i in range(0, len(texts), batch_size):
            batch = texts[i:i + batch_size]
            try:
                response = self.co.embed(
                    texts=batch,
                    model=self.model,
                    input_type="search_document"
                )
                embeddings.extend(response.embeddings)
            except Exception as e:
                print(f"[WARNING] Cohere API error for batch {i}: {e}")
                embeddings.extend([[0.0] * self.dimensions] * len(batch))
        
        return embeddings


class AnthropicEmbeddings:
    """
    Anthropic embeddings (if/when available)
    
    Note: As of Oct 2025, Anthropic doesn't provide embeddings API.
    This is a placeholder for future compatibility.
    """
    
    def __init__(self, api_key: str = None, **kwargs):
        raise NotImplementedError("Anthropic embeddings not yet available")


class HashEmbeddings:
    """
    Simple hash-based embeddings (fallback)
    
    Pros:
    - No dependencies
    - Deterministic
    - Fast
    
    Cons:
    - Low quality
    - No semantic meaning
    - Not suitable for production
    """
    
    def __init__(self, dimensions: int = 16, **kwargs):
        self.dimensions = dimensions
        print(f"[WARNING] Using hash-based embeddings ({dimensions} dimensions)")
        print("    For production, consider sentence-transformers or API-based embeddings")
    
    def embed(self, text: str) -> List[float]:
        """Generate simple hash-based embedding"""
        import hashlib
        
        if not text:
            return [0.0] * self.dimensions
        
        hash_val = int(hashlib.md5(text.encode()).hexdigest(), 16)
        
        embedding = []
        for i in range(self.dimensions):
            val = ((hash_val >> (i * 8)) % 200 - 100) / 100.0
            embedding.append(round(val, 3))
        
        return embedding
    
    def embed_batch(self, texts: List[str], **kwargs) -> List[List[float]]:
        """Generate embeddings for multiple texts"""
        return [self.embed(text) for text in texts]


# ============================================================================
# Integration with LLMR Generator
# ============================================================================

def integrate_embeddings(llmr_generator_path: str, embedding_provider: str = "auto", **kwargs):
    """
    Modify the LLMR generator to use production embeddings
    
    Usage:
        python embedding_integration.py sentence-transformers
        python embedding_integration.py openai --api_key=sk-...
        python embedding_integration.py cohere --api_key=...
    """
    
    # Create embedding provider
    embedder = EmbeddingProviderFactory.create(embedding_provider, **kwargs)
    
    print(f"\n{'='*60}")
    print(f"Embedding Provider: {embedding_provider}")
    print(f"Dimensions: {embedder.dimensions}")
    print(f"{'='*60}\n")
    
    # Example: Generate embeddings for sample texts
    sample_texts = [
        "This is a blog post about Python programming",
        "How to debug web applications effectively",
        "Introduction to machine learning",
        "Product specifications for laptop",
        "Upcoming conference on AI safety"
    ]
    
    print("Generating sample embeddings...")
    embeddings = embedder.embed_batch(sample_texts)
    
    print(f"\nGenerated {len(embeddings)} embeddings")
    print(f"Sample embedding (first 5 dims): {embeddings[0][:5]}")
    
    return embedder


# ============================================================================
# CLI
# ============================================================================

def main():
    """Command-line interface"""
    import argparse
    
    parser = argparse.ArgumentParser(description="Test embedding providers")
    parser.add_argument("provider", nargs="?", default="auto",
                       choices=["auto", "sentence-transformers", "openai", "cohere", "hash"],
                       help="Embedding provider to use")
    parser.add_argument("--openai-api-key", help="OpenAI API key")
    parser.add_argument("--cohere-api-key", help="Cohere API key")
    parser.add_argument("--model", help="Model name")
    parser.add_argument("--test", action="store_true", help="Run test")
    
    args = parser.parse_args()
    
    kwargs = {}
    if args.openai_api_key:
        kwargs["api_key"] = args.openai_api_key
    if args.cohere_api_key:
        kwargs["api_key"] = args.cohere_api_key
    if args.model:
        kwargs["model"] = args.model
    
    embedder = integrate_embeddings(
        "generate_llmr.py",
        args.provider,
        **kwargs
    )
    
    if args.test:
        print("\n" + "="*60)
        print("Running embedding test...")
        print("="*60 + "\n")
        
        test_text = "The quick brown fox jumps over the lazy dog"
        embedding = embedder.embed(test_text)
        
        print(f"Text: {test_text}")
        print(f"Embedding dimensions: {len(embedding)}")
        print(f"Embedding sample: {embedding[:10]}")
        print(f"Embedding range: [{min(embedding):.3f}, {max(embedding):.3f}]")


if __name__ == "__main__":
    main()
