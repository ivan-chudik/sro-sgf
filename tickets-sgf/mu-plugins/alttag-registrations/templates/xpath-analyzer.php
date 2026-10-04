<?php

class XPathAnalyzer 
{
    private $dom;
    private $xpath;

    public function analyze($html) 
    {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $this->xpath = new DOMXPath($this->dom);

        $results = [];
        $textNodes = $this->xpath->query('//text()');
        
        foreach ($textNodes as $node) {
            $text = trim($node->nodeValue);
            if (empty($text)) continue;

            $path = $this->getNodePath($node->parentNode);
            if (str_starts_with($path, '/#document/')) {
                $path = substr($path, 11);
            }
            
            $results[] = [
                'text' => $text,
                'xpath' => $path
            ];
        }

        return $results;
    }

    private function getNodePath(DOMNode $node) 
    {
        $path = '';
        $current = $node;

        while ($current !== null) {
            $step = $this->getPathStep($current);
            if ($step) {
                $path = $step . ($path ? '/' . $path : '');
            }
            $current = $current->parentNode;
        }

        return '/' . $path;
    }

    private function getPathStep(DOMNode $node) 
    {
        $tag = strtolower($node->nodeName);
        
        // Skip counting if it's an html or body tag
        if (in_array($tag, ['html', 'body'])) {
            return $tag;
        }

        $position = 1;
        $sibling = $node->previousSibling;
        $needsIndex = false;

        while ($sibling) {
            if ($sibling->nodeType === XML_ELEMENT_NODE && 
                $sibling->nodeName === $node->nodeName) {
                $position++;
                $needsIndex = true;
            }
            $sibling = $sibling->previousSibling;
        }

        // Check if there are any following siblings of same type
        $sibling = $node->nextSibling;
        while (!$needsIndex && $sibling) {
            if ($sibling->nodeType === XML_ELEMENT_NODE && 
                $sibling->nodeName === $node->nodeName) {
                $needsIndex = true;
            }
            $sibling = $sibling->nextSibling;
        }

        return $tag . ($needsIndex ? "[$position]" : '');
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>PHP XPath Analyzer</title>
    <style>
        body { 
            font-family: system-ui; 
            max-width: 1200px; 
            margin: 20px auto; 
            padding: 20px; 
            background: #f5f5f5; 
        }
        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        textarea { 
            width: 100%; 
            height: 200px; 
            margin: 10px 0; 
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: monospace;
        }
        button {
            background: #4a90e2;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #357abd;
        }
        .result { 
            margin: 10px 0; 
            padding: 15px; 
            background: #f8f9fa; 
            border: 1px solid #e9ecef;
            border-radius: 4px;
        }
        .xpath { 
            font-family: monospace; 
            background: #e9ecef; 
            padding: 5px; 
            border-radius: 3px;
            margin-top: 5px;
            word-break: break-all;
        }
        .copy-button {
            background: #6c757d;
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>PHP XPath Analyzer</h1>
        <form method="post">
            <textarea name="html" placeholder="Paste your HTML here..."><?php echo htmlspecialchars($_POST['html'] ?? ''); ?></textarea>
            <br>
            <button type="submit">Analyze HTML</button>
        </form>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['html'])) {
            $analyzer = new XPathAnalyzer();
            $results = $analyzer->analyze($_POST['html']);

            foreach ($results as $result) {
                echo '<div class="result">';
                echo '<div>Text: ' . htmlspecialchars($result['text']) . '</div>';
                echo '<div class="xpath">XPath: ' . htmlspecialchars($result['xpath']) . 
                     '<button class="copy-button" onclick="copyToClipboard(this.parentNode.textContent.substring(7))">Copy</button></div>';
                echo '</div>';
            }
        }
        ?>
    </div>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('XPath copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }
    </script>
</body>
</html>