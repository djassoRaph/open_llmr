#!/usr/bin/env node
/**
 * LLMR Generator - Node.js CLI Tool
 * Author: Raphaël Reck with Claude AI enhancements Opus 4.1 / 30.06.2024
 * License: MIT
 * 
 * Underconstruction still not tested
 * 
 * Make websites AI-agent friendly with structured, compressed content
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const crypto = require('crypto');

class LLMRGenerator {
    constructor(options = {}) {
        this.rootDir = options.rootDir || process.cwd();
        this.outputFile = options.output || 'site.llmr';
        this.config = {
            includePatterns: options.include || ['**/*.html', '**/*.md'],
            excludePatterns: options.exclude || ['node_modules/**', '.git/**'],
            compression: options.compression !== false,
            includeDynamic: options.dynamic || false,
            baseUrl: options.baseUrl || null
        };
        
        this.stats = {
            filesProcessed: 0,
            originalSize: 0,
            compressedSize: 0,
            startTime: Date.now()
        };
    }

    /**
     * Process React/Vue/Angular apps by pre-rendering
     */
    async processSPA() {
        if (!this.config.includeDynamic) return null;
        
        console.log('🔍 Detecting SPA framework...');
        
        const frameworks = {
            react: {
                detector: 'package.json:react',
                prerender: 'npx react-snap',
                output: 'build'
            },
            vue: {
                detector: 'package.json:vue',
                prerender: 'npm run generate',
                output: 'dist'
            },
            angular: {
                detector: 'package.json:@angular/core',
                prerender: 'npm run build:ssr',
                output: 'dist'
            },
            next: {
                detector: 'package.json:next',
                prerender: 'npm run build && npm run export',
                output: 'out'
            }
        };
        
        const packageJson = this.readPackageJson();
        if (!packageJson) return null;
        
        for (const [framework, config] of Object.entries(frameworks)) {
            if (this.detectFramework(packageJson, config.detector)) {
                console.log(`Detected ${framework} app`);
                return this.prerenderSPA(config);
            }
        }
        
        return null;
    }
    
    detectFramework(packageJson, detector) {
        const [file, key] = detector.split(':');
        if (file === 'package.json') {
            return packageJson.dependencies?.[key] || 
                   packageJson.devDependencies?.[key];
        }
        return false;
    }
    
    readPackageJson() {
        const packagePath = path.join(this.rootDir, 'package.json');
        if (fs.existsSync(packagePath)) {
            return JSON.parse(fs.readFileSync(packagePath, 'utf8'));
        }
        return null;
    }
    
    /**
     * Extract structured data from HTML
     */
    extractStructuredData(html, filePath) {
        const data = {
            path: path.relative(this.rootDir, filePath),
            content: '',
            metadata: {},
            structure: {}
        };
        
        // Extract JSON-LD
        const jsonLdRegex = /<script[^>]*type=["']application\/ld\+json["'][^>]*>(.*?)<\/script>/gis;
        const jsonLdMatches = [...html.matchAll(jsonLdRegex)];
        
        if (jsonLdMatches.length > 0) {
            data.metadata.jsonLd = jsonLdMatches.map(match => {
                try {
                    return JSON.parse(match[1]);
                } catch (e) {
                    return null;
                }
            }).filter(Boolean);
        }
        
        // Extract meta tags
        const metaRegex = /<meta\s+([^>]+)>/gi;
        const metaTags = {};
        let metaMatch;
        
        while ((metaMatch = metaRegex.exec(html)) !== null) {
            const attrs = this.parseAttributes(metaMatch[1]);
            if (attrs.name || attrs.property) {
                const key = attrs.name || attrs.property;
                metaTags[key] = attrs.content;
            }
        }
        
        data.metadata.meta = metaTags;
        
        // Extract semantic structure
        const semanticElements = [
            'header', 'nav', 'main', 'article', 'section', 
            'aside', 'footer', 'h1', 'h2', 'h3'
        ];
        
        semanticElements.forEach(tag => {
            const regex = new RegExp(`<${tag}[^>]*>(.*?)<\/${tag}>`, 'gis');
            const matches = html.match(regex);
            if (matches) {
                data.structure[tag] = matches.length;
            }
        });
        
        // Extract text content (remove all tags)
        let textContent = html
            .replace(/<script[^>]*>.*?<\/script>/gis, '')
            .replace(/<style[^>]*>.*?<\/style>/gis, '')
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        
        data.content = textContent;
        
        // Generate embedding hints
        data.embeddings = this.generateEmbeddings(textContent, data.metadata);
        
        return data;
    }
    
    parseAttributes(attrString) {
        const attrs = {};
        const regex = /(\w+)(?:=["']([^"']+)["'])?/g;
        let match;
        
        while ((match = regex.exec(attrString)) !== null) {
            attrs[match[1]] = match[2] || true;
        }
        
        return attrs;
    }
    
    /**
     * Generate embedding hints for AI
     */
    generateEmbeddings(content, metadata) {
        const embeddings = {
            summary: content.substring(0, 500).replace(/\s+/g, ' '),
            keywords: [],
            topics: [],
            contentType: this.detectContentType(content, metadata),
            language: this.detectLanguage(content, metadata)
        };
        
        // Extract keywords from meta tags
        if (metadata.meta?.keywords) {
            embeddings.keywords = metadata.meta.keywords.split(',').map(k => k.trim());
        }
        
        // Detect topics from headings
        const headingRegex = /<h[1-3][^>]*>(.*?)<\/h[1-3]>/gi;
        const headings = [...content.matchAll(headingRegex)].map(m => 
            m[1].replace(/<[^>]+>/g, '').trim()
        );
        embeddings.topics = headings.slice(0, 5);
        
        return embeddings;
    }
    
    detectContentType(content, metadata) {
        if (metadata.jsonLd?.some(ld => ld['@type'] === 'BlogPosting')) {
            return 'blog';
        }
        if (metadata.jsonLd?.some(ld => ld['@type'] === 'Product')) {
            return 'product';
        }
        if (metadata.meta?.['og:type']) {
            return metadata.meta['og:type'];
        }
        return 'website';
    }
    
    detectLanguage(content, metadata) {
        return metadata.meta?.['og:locale'] || 
               metadata.meta?.language || 
               'en';
    }
    
    /**
     * Generate the LLMR file
     */
    async generate() {
        console.log('Starting LLMR generation...');
        console.log(`Root directory: ${this.rootDir}`);
        
        // Check for SPA and prerender if needed
        await this.processSPA();
        
        const llmrData = {
            version: '2.0',
            generator: '@raphaelreck/llmr-generator',
            created: new Date().toISOString(),
            site: {
                url: this.config.baseUrl || this.extractBaseUrl(),
                title: null,
                description: null,
                language: 'en'
            },
            pages: [],
            embeddings: {
                topics: new Set(),
                keywords: new Set(),
                contentTypes: new Set()
            },
            compression: {
                algorithm: 'semantic',
                ratio: 0
            }
        };
        
        // Process all HTML files
        const files = this.findFiles('**/*.html');
        
        for (const file of files) {
            console.log(`Processing: ${file}`);
            const html = fs.readFileSync(file, 'utf8');
            this.stats.originalSize += Buffer.byteLength(html);
            
            const pageData = this.extractStructuredData(html, file);
            llmrData.pages.push(pageData);
            
            // Aggregate embeddings
            pageData.embeddings.keywords.forEach(k => llmrData.embeddings.keywords.add(k));
            pageData.embeddings.topics.forEach(t => llmrData.embeddings.topics.add(t));
            llmrData.embeddings.contentTypes.add(pageData.embeddings.contentType);
            
            // Extract site-level metadata from index
            if (file.endsWith('index.html') && !llmrData.site.title) {
                llmrData.site.title = pageData.metadata.meta?.['og:site_name'] || 
                                     pageData.metadata.meta?.title;
                llmrData.site.description = pageData.metadata.meta?.description;
            }
            
            this.stats.filesProcessed++;
        }
        
        // Convert Sets to Arrays
        llmrData.embeddings.topics = Array.from(llmrData.embeddings.topics);
        llmrData.embeddings.keywords = Array.from(llmrData.embeddings.keywords);
        llmrData.embeddings.contentTypes = Array.from(llmrData.embeddings.contentTypes);
        
        // Generate final LLMR
        const llmrJson = JSON.stringify(llmrData, null, 2);
        this.stats.compressedSize = Buffer.byteLength(llmrJson);
        
        // Calculate compression ratio
        llmrData.compression.ratio = 
            ((this.stats.originalSize - this.stats.compressedSize) / this.stats.originalSize * 100).toFixed(2);
        
        // Write LLMR file
        const outputPath = path.join(this.rootDir, this.outputFile);
        fs.writeFileSync(outputPath, JSON.stringify(llmrData, null, 2));
        
        // Print statistics
        this.printStats(outputPath);
        
        // Update HTML files with LLMR reference
        this.updateHtmlReferences();
        
        return llmrData;
    }
    
    findFiles(pattern) {
        const glob = require('glob');
        return glob.sync(pattern, {
            cwd: this.rootDir,
            absolute: true,
            ignore: this.config.excludePatterns
        });
    }
    
    extractBaseUrl() {
        // Try to extract from CNAME, package.json, or git remote
        const cnamePath = path.join(this.rootDir, 'CNAME');
        if (fs.existsSync(cnamePath)) {
            const domain = fs.readFileSync(cnamePath, 'utf8').trim();
            return `https://${domain}`;
        }
        
        const pkg = this.readPackageJson();
        if (pkg?.homepage) {
            return pkg.homepage;
        }
        
        try {
            const gitUrl = execSync('git config --get remote.origin.url', {
                cwd: this.rootDir
            }).toString().trim();
            
            if (gitUrl.includes('github.com')) {
                const match = gitUrl.match(/github\.com[:/]([^/]+)\/([^.]+)/);
                if (match) {
                    return `https://${match[1]}.github.io/${match[2]}`;
                }
            }
        } catch (e) {
            // Git not available or not a git repo
        }
        
        return null;
    }
    
    updateHtmlReferences() {
        const indexPath = path.join(this.rootDir, 'index.html');
        if (!fs.existsSync(indexPath)) return;
        
        let html = fs.readFileSync(indexPath, 'utf8');
        
        // Check if LLMR reference already exists
        if (html.includes('rel="llm-index"')) {
            console.log('LLMR reference already exists in index.html');
            return;
        }
        
        // Add LLMR reference to head
        const llmrLink = `    <link rel="llm-index" type="application/json" href="/${this.outputFile}">`;
        html = html.replace('</head>', `${llmrLink}\n</head>`);
        
        fs.writeFileSync(indexPath, html);
        console.log('Added LLMR reference to index.html');
    }
    
    printStats(outputPath) {
        const duration = ((Date.now() - this.stats.startTime) / 1000).toFixed(2);
        
        console.log('\nLLMR Generation Complete!');
        console.log('═'.repeat(50));
        console.log(`Output: ${outputPath}`);
        console.log(`Files processed: ${this.stats.filesProcessed}`);
        console.log(`Original size: ${this.formatBytes(this.stats.originalSize)}`);
        console.log(`LLMR size: ${this.formatBytes(this.stats.compressedSize)}`);
        console.log(`Compression: ${((this.stats.originalSize - this.stats.compressedSize) / this.stats.originalSize * 100).toFixed(2)}%`);
        console.log(`⏱ Duration: ${duration}s`);
        console.log('═'.repeat(50));
        console.log('\n Your site is now AI-agent friendly!');
        console.log('Don\'t forget to add to robots.txt:');
        console.log(`Sitemap: ${this.config.baseUrl || 'https://yoursite.com'}/${this.outputFile}`);
    }
    
    formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }
}

// CLI Interface
if (require.main === module) {
    const args = process.argv.slice(2);
    const options = {
        rootDir: process.cwd(),
        output: 'site.llmr',
        dynamic: false
    };
    
    // Parse command line arguments
    for (let i = 0; i < args.length; i++) {
        switch(args[i]) {
            case '--output':
            case '-o':
                options.output = args[++i];
                break;
            case '--dynamic':
            case '-d':
                options.dynamic = true;
                break;
            case '--url':
            case '-u':
                options.baseUrl = args[++i];
                break;
            case '--help':
            case '-h':
                console.log(`
LLMR Generator - Make your website AI-agent friendly

Usage: llmr [options]

Options:
  -o, --output <file>   Output filename (default: site.llmr)
  -d, --dynamic         Process dynamic content (SPA)
  -u, --url <url>       Base URL of your site
  -h, --help           Show this help message

Examples:
  llmr                           Generate site.llmr in current directory
  llmr -o public/ai-index.llmr   Custom output location
  llmr -d -u https://mysite.com  Process SPA with base URL
                `);
                process.exit(0);
        }
    }
    
    const generator = new LLMRGenerator(options);
    generator.generate().catch(console.error);
}

module.exports = LLMRGenerator;