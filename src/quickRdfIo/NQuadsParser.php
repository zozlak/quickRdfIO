<?php

/*
 * The MIT License
 *
 * Copyright 2021 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace quickRdfIo;

use Generator;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\Parser as iParser;
use rdfInterface\Quad as iQuad;
use rdfInterface\DataFactory as iDataFactory;

// TODO - n-quads-star:
// https://w3c.github.io/rdf-star/cg-spec/editors_draft.html#n-triples-star
// and
// https://w3c.github.io/rdf-star/tests/nt/syntax/manifest.html

/**
 * Parses only n-quads and n-triples but does it fast (thanks to parsing in chunks
 * and extensive use of regullar expressions).
 *
 * @author zozlak
 */
class NQuadsParser implements iParser, iQuadIterator {

    const MODE_TRIPLES      = 1;
    const MODE_QUADS        = 2;
    const MODE_TRIPLES_STAR = 3;
    const MODE_QUADS_STAR   = 4;
    const EOL               = '[\x0D\x0A]+';
    const UCHAR             = '\\\\u[0-9A-Fa-f]{4}|\\\\U[0-9A-Fa-f]{8}';
    const COMMENT_STRICT    = '\s*(?:#[^\x0D\x0A]*)?';
    const COMMENT           = '\s*(?:#.*)?';
    const COMMENT2_STRICT   = '\s*#[^\x0D\x0A]*';
    const COMMENT2          = '\s*#.*';
    const LANGTAG_STRICT    = '@([a-zA-Z]+(?:-[a-zA-Z0-9]+)*)';
    const LANGTAG           = '@([-a-zA-Z0-9]+)';
    const IRIREF_STRICT     = '<((?:[^\x{00}-\x{20}<>"{}|^`\\\\]|\\\\u[0-9A-Fa-f]{4}|\\\\U[0-9A-Fa-f]{8})*)>';
    const IRIREF            = '<([^>]+)>';
    const BLANKNODE1_STRICT = '_:';
    const BLANKNODE2_STRICT = '[0-9_:A-Za-z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0370}-\x{037D}\x{037F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}]';
    const BLANKNODE3_STRICT = '[-0-9_:A-Za-z\x{00B7}\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0300}-\x{037D}\x{037F}-\x{1FFF}\x{200C}-\x{200D}\x{203F}-\x{2040}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}.]';
    const BLANKNODE4_STRICT = '[-0-9_:A-Za-z\x{00B7}\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0300}-\x{037D}\x{037F}-\x{1FFF}\x{200C}-\x{200D}\x{203F}-\x{2040}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}]';
    const BLANKNODE         = '(_:[^\s<.]+)';
    const LITERAL_STRICT    = '"((?:[^\x{22}\x{5C}\x{0A}\x{0D}]|\\\\[tbnrf"\'\\\\]|\\\\u[0-9A-Fa-f]{4}|\\\\U[0-9A-Fa-f]{8})*)"';
    const LITERAL           = '"((?:[^"]|\\")*)"';
    const STAR_START        = '%\\G\s*<<%';
    const STAR_END          = '%\\G\s*>>%';
    use TmpStreamTrait;

    private iDataFactory $dataFactory;

    /**
     *
     * @var resource
     */
    private $input;
    private int $mode;
    // non-star parser regexp
    private string $regexp;
    // star parser regexps
    private string $regexpSbjPred;
    private string $regexpObjGraph;
    private string $regexpPred;
    private string $regexpGraph;
    private string $regexpLineEnd;
    private string $regexpCommentLine;

    /**
     * Input line
     * @var string
     */
    private string $line;

    /**
     * Character offset within a parsed line (used by the star parser)
     * @var int
     */
    private int $offset;

    /**
     * Recursion level of the start parser
     * @var int
     */
    private int $level;

    /**
     * 
     * @var Generator<iQuad>
     */
    private Generator $quads;

    /**
     * Creates the parser.
     * 
     * Parser can work in four different modes according to `$strict` and `$ntriples`
     * parameter values.
     * 
     * When `$strict = true` regular expressions following strictly n-triples/n-quads
     * formal definition are used (see https://www.w3.org/TR/n-quads/#sec-grammar and
     * https://www.w3.org/TR/n-triples/#n-triples-grammar). When `$strict = false`
     * simplified regular expressions are used. Simplified variants provide a little
     * faster parsing and are (much) easier to debug. All data which are valid according
     * to the strict syntax can be properly parsed in the simplified mode, therefore
     * until you need to check the input is 100% correct RDF, you may just stick to
     * simplified mode.
     * 
     * @param iDataFactory $dataFactory factory to be used to generate RDF terms.
     * @param bool $strict should strict RDF syntax be enforced?
     * @param int $mode parsing mode - one of modes listed below. It's worth noting
     *   that \quickRdfIo\NQuadsParser::MODE_QUADS_STAR is able to parse all 
     *   others and there should be no significant performance difference between
     *   different parsing modes. They main reason for using non-default one is
     *   to assure the input data follow a given format.
     *   - \quickRdfIo\NQuadsParser::MODE_TRIPLES,
     *   - \quickRdfIo\NQuadsParser::MODE_QUADS, 
     *   - \quickRdfIo\NQuadsParser::MODE_TRIPLES_STAR
     *   - \quickRdfIo\NQuadsParser::MODE_QUADS_STAR
     */
    public function __construct(iDataFactory $dataFactory, bool $strict = false,
                                int $mode = self::MODE_QUADS_STAR) {
        $this->dataFactory = $dataFactory;
        $this->mode        = $mode;
        if ($strict) {
            $comment = self::COMMENT_STRICT;
            $eol     = self::EOL . '$';
            $iri     = self::IRIREF_STRICT;
            $blank   = '(' . self::BLANKNODE1_STRICT . self::BLANKNODE2_STRICT . '(?:' . self::BLANKNODE3_STRICT . '*' . self::BLANKNODE4_STRICT . ')?)';
            $lang    = self::LANGTAG_STRICT;
            $literal = self::LITERAL_STRICT;
            $lineEnd = "\\s*\\.$comment$eol";
            $flags   = 'u';
        } else {
            $comment = self::COMMENT . self::EOL;
            $eol     = '';
            $iri     = self::IRIREF;
            $blank   = self::BLANKNODE;
            $lang    = self::LANGTAG;
            $literal = self::LITERAL;
            $lineEnd = "\\s*\\.";
            $flags   = '';
        }
        $graph = '';
        if ($mode === self::MODE_QUADS || $mode === self::MODE_QUADS_STAR) {
            $graph = "(?:\\s*(?:$iri|$blank))?";
        }
        if ($mode === self::MODE_TRIPLES || $mode === self::MODE_QUADS) {
            $this->regexp = "%^$comment$eol|^\\s*(?:$iri|$blank)\\s*$iri\\s*(?:$iri|$blank|$literal(?:\\^\\^$iri|$lang)?)$graph$lineEnd%$flags";
        } else {
            $this->regexpSbjPred     = "%\\G\\s*(?:$iri|$blank)\\s*$iri\\s*%$flags"; // subject and predicate (or comment)
            $this->regexpObjGraph    = "%\\G\\s*(?:$iri|$blank|$literal(?:\\^\\^$iri|$lang)?)$graph%$flags";
            $this->regexpPred        = "%\\G\\s*$iri\\s*%$flags";
            $this->regexpGraph       = "%\\G\\s*$graph%$flags";
            $this->regexpLineEnd     = "%\\G$lineEnd%$flags";
            $comment                 = $strict ? self::COMMENT2_STRICT : self::COMMENT2;
            $this->regexpCommentLine = "%^($comment|\\s*)$eol$%";
        }
    }

    public function __destruct() {
        $this->closeTmpStream();
    }

    public function parseStream($input): iQuadIterator {
        if (!is_resource($input)) {
            throw new RdfIoException("Input has to be a resource");
        }

        $this->input = $input;
        return $this;
    }

    public function current(): iQuad {
        return $this->quads->current();
    }

    public function key() {
        return $this->quads->key();
    }

    public function next(): void {
        $this->quads->next();
    }

    public function rewind(): void {
        if (ftell($this->input) !== 0) {
            $ret = rewind($this->input);
            if ($ret !== true) {
                throw new RdfIoException("Can't seek in the input stream");
            }
        }
        if ($this->mode === self::MODE_TRIPLES || $this->mode === self::MODE_QUADS) {
            $this->quads = $this->quadGenerator();
        } else {
            $this->quads = $this->starQuadGenerator();
        }
    }

    public function valid(): bool {
        return $this->quads->valid();
    }

    /**
     * 
     * @return Generator<iQuad>
     * @throws RdfIoException
     */
    private function quadGenerator(): Generator {
        $matches = null;
        $n       = 0;
        $loop    = true;
        while ($loop) {
            $n++;
            $this->line = fgets($this->input);
            $loop       = !feof($this->input);
            if (!$loop) {
                $this->line .= "\n"; // add new line to avoid issues with last line without \n
            }
            $ret = preg_match($this->regexp, $this->line, $matches, PREG_UNMATCHED_AS_NULL);
            if ($ret === 0 && !empty(trim($this->line))) {
                throw new RdfIoException("Can't parse line $n: " . $this->line);
            }
            if ($matches[3] ?? null !== null) {
                yield $this->makeQuad($matches);
            }
        }
    }

    /**
     * Converts regex matches array into a Quad.
     * 
     * @param array<?string> $matches
     * @return iQuad
     */
    private function makeQuad(array &$matches): iQuad {
        $df = $this->dataFactory;

        if ($matches[1] !== null) {
            $sbj = $df::namedNode($this->unescapeUnicode($matches[1]));
        } else {
            $sbj = $df::blankNode($matches[2]);
        }

        $pred = $df::namedNode($this->unescapeUnicode($matches[3] ?? ''));

        if ($matches[4] !== null) {
            $obj = $df::namedNode($matches[4]);
        } elseif ($matches[5] !== null) {
            $obj = $df::blankNode($matches[5]);
        } else {
            $value = $matches[6] ?? '';
            $value = $this->unescapeUnicode($value);
            $obj   = $df::literal($value, $matches[8], $matches[7]);
        }
        if ($matches[9] ?? null !== null) {
            $graph = $df::namedNode($matches[9]);
        } elseif ($matches[10] ?? null !== null) {
            $graph = $df::blankNode($matches[10]);
        }
        return $df::quad($sbj, $pred, $obj, $graph ?? null);
    }

    /**
     * 
     * @return Generator<iQuad>
     * @throws RdfIoException
     */
    private function starQuadGenerator(): Generator {
        $n    = 0;
        $loop = true;
        while ($loop) {
            $n++;
            $this->offset = 0;
            $this->level  = 0;
            $this->line   = fgets($this->input);
            $loop         = !feof($this->input);
            if (!$loop) {
                $this->line .= "\n"; // add new line to avoid issues with last line without \n
            }
            try {
                yield $this->parseStar();
            } catch (RdfIoException $e) {
                $ret = preg_match($this->regexpCommentLine, $this->line);
                if ($ret === 0) {
                    throw $e;
                }
            }
        }
    }

    private function parseStar(): iQuad {
        //echo str_repeat("\t", $this->level) . "parsing " . substr($this->line, $this->offset);
        $matches = null;
        if (preg_match(self::STAR_START, $this->line, $matches, 0, $this->offset)) {
            $this->offset += strlen($matches[0]);
            $this->level++;
            $sbj          = $this->parseStar();
            $ret          = preg_match($this->regexpPred, $this->line, $matches, PREG_UNMATCHED_AS_NULL, $this->offset);
            if ($ret === 0) {
                throw new RdfIoException("Failed parsing predicate " . substr($this->line, $this->offset));
            }
            $this->offset += strlen($matches[0]);
            $pred         = $this->dataFactory::namedNode($matches[1]);
        } else {
            $ret = preg_match($this->regexpSbjPred, $this->line, $matches, PREG_UNMATCHED_AS_NULL, $this->offset);
            if ($ret === 0) {
                throw new RdfIoException("Failed parsing subject and predicate " . substr($this->line, $this->offset));
            }
            $this->offset += strlen($matches[0]);
            if ($matches[1] !== null) {
                $sbj = $this->dataFactory::namedNode($this->unescapeUnicode($matches[1]));
            } else {
                $sbj = $this->dataFactory::blankNode($matches[2]);
            }
            $pred = $this->dataFactory::namedNode($matches[3]);
        }
        // is object star?
        if (preg_match(self::STAR_START, $this->line, $matches, 0, $this->offset)) {
            $this->offset += strlen($matches[0]);
            $this->level++;
            $obj          = $this->parseStar();
            $ret          = preg_match($this->regexpGraph, $this->line, $matches, PREG_UNMATCHED_AS_NULL, $this->offset);
            $this->offset += strlen($matches[0]);
            if ($matches[1] ?? null !== null) {
                $graph = $this->dataFactory::namedNode($matches[1]);
            } else if ($matches[2] ?? null !== null) {
                $graph = $this->dataFactory::blankNode($matches[2]);
            }
        } else {
            $ret = preg_match($this->regexpObjGraph, $this->line, $matches, PREG_UNMATCHED_AS_NULL, $this->offset);
            if ($ret === 0) {
                throw new RdfIoException("Can't parse object " . substr($this->line, $this->offset));
            }
            $this->offset += strlen($matches[0]);
            if ($matches[1] !== null) {
                $obj = $this->dataFactory::namedNode($matches[1]);
            } elseif ($matches[2] !== null) {
                $obj = $this->dataFactory::blankNode($matches[2]);
            } else {
                $value = $matches[3] ?? '';
                $value = $this->unescapeUnicode($value);
                $obj   = $this->dataFactory::literal($value, $matches[5], $matches[4]);
            }
            if ($matches[6] ?? null !== null) {
                $graph = $this->dataFactory::namedNode($matches[6]);
            } elseif ($matches[7] ?? null !== null) {
                $graph = $this->dataFactory::blankNode($matches[7]);
            }
        }
        $regexpEnd = $this->level > 0 ? self::STAR_END : $this->regexpLineEnd;
        $ret       = preg_match($regexpEnd, $this->line, $matches, 0, $this->offset);
        if ($ret === 0) {
            throw new RdfIoException("Can't parse end " . substr($this->line, $this->offset));
        }
        $this->offset += strlen($matches[0]);
        $quad         = $this->dataFactory::quad($sbj, $pred, $obj, $graph ?? null);
        $this->level--;
        return $quad;
    }

    private function unescapeUnicode(string $value): string {
        $escapes = null;
        $count   = preg_match_all('%' . self::UCHAR . '%', $value, $escapes);
        if ($count > 0) {
            $dict = [];
            foreach ($escapes[0] as $i) {
                $dict[$i] = mb_chr((int) hexdec(substr($i, 2)));
            }
            $value = strtr($value, $dict);
        }
        return $value;
    }
}