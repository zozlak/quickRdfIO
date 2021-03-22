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

use quickRdf\DataFactory as DF;
use termTemplates\QuadTemplate;

/**
 * Description of NQuadsParserTest
 *
 * @author zozlak
 */
class NQuadsParserTest extends \PHPUnit\Framework\TestCase {

    public function testBig(): void {
        $parser = new NQuadsParser(new DF(), true, true);
        $n      = 0;
        $N      = -1;
        $stream = fopen(__DIR__ . '/puzzle4d_100k.ntriples', 'r');
        if ($stream) {
            $tmpl = new QuadTemplate(DF::namedNode('https://technical#subject'), DF::namedNode('https://technical#tripleCount'));
            foreach ($parser->parseStream($stream) as $i) {
                $n++;
                if ($N < 0 && $tmpl->equals($i)) {
                    $N = (int) (string) $i->getObject()->getValue();
                }
            }
            fclose($stream);
        }
        $this->assertEquals($N, $n);
    }
}