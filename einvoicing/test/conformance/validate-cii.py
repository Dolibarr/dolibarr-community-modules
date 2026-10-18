#!/usr/bin/env python3
"""Validate a CII document the way the platform does, with the FNFE (France_RFE) rules.

The chain is chosen from the document's own GuidelineSpecifiedDocumentContextParameter (BT-24),
exactly like the online validator, and it has THREE stages:

  1. the CII D22B XSD;
  2. the XSLT OF THE DECLARED PROFILE, the stage a hand-rolled Saxon call usually forgets. It is the
     one that carries the FX-SCH-A-* rules and the EN 16931 arithmetic - BR-CO-17, BR-S-08, BR-S-09,
     the three the documents of #709 failed on;
  3. the French CTC rules, BR-FR-Flux2.

Running only stages 1 and 3 answers "0 failure" on documents the access point refuses.

A Schematron refuses in two ways, and both count. Besides the assertion that fails
(svrl:failed-assert), a <report> that fires produces an svrl:successful-report: that is how a profile
XSLT states "Element 'ram:X' is marked as not used in the given context" for everything the profile
leaves out. The platform returns those as REJ_COH, so reading only the failed assertions answers
VALID on a document it rejects - the shape of #974, ram:ExemptionReason on a line under EN 16931.

Usage:
    validate-cii.py [--quiet] [--strict] file.xml [file.xml ...]

    --quiet    one line per document, no rule detail
    --strict   run BR-FR-Flux2-Schematron-CII.xslt (fatal flags) instead of the _WARNING variant,
               which is the one the online validator runs

Environment:
    FNFE_ROOT  the FNFE_RFE_INVOICE directory of a France_RFE release (EUPL,
               https://github.com/fnfempe/France_RFE)
    SAXON_JAR  a Saxon-HE jar
    PHP_BIN    the PHP binary used for the XSD stage (default: php)

Exit code: 0 when every document passes, 1 when any fails, 2 on a setup problem.
"""
import os
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

FNFE_ROOT = os.environ.get('FNFE_ROOT', '')
SAXON_JAR = os.environ.get('SAXON_JAR', '')
PHP_BIN = os.environ.get('PHP_BIN', 'php')

XSD = os.path.join(FNFE_ROOT, 'CII', '1xsd-CII_D22B_uncoupled', 'CrossIndustryInvoice_100pD22B.xsd')

# Specification identifier (BT-24) -> the directory holding the profile XSLT, and its file name. The
# BR-FR stage lives in that same directory, which is why the two are one entry. A profile mapped to
# None is one the FNFE package ships no validator for: the document is reported SKIP, never green.
PROFILES = {
    'urn:factur-x.eu:1p0:minimum': None,
    'urn:factur-x.eu:1p0:basicwl': ('Factur-X/BASICWL/2xslt', 'FACTUR-X_BASIC-WL.xslt'),
    'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic': None,
    'urn:cen.eu:en16931:2017': ('Factur-X/EN16931/2xslt', 'FACTUR-X_EN16931.xslt'),
    'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended':
        ('Factur-X/EXTENDED/2xslt', 'FACTUR-X_EXTENDED.xslt'),
    'urn:cen.eu:en16931:2017#conformant#urn.cpro.gouv.fr:1p0:extended-ctc-fr':
        ('CII/EXTENDED-CTC-FR/2xslt', 'EXTENDED-CTC-FR-CII.xslt'),
}

RAM = '{urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100}'


def die(msg):
    """Report a setup problem and stop."""
    sys.stderr.write('validate-cii: %s\n' % msg)
    sys.exit(2)


def guideline(path):
    """Read the specification identifier (BT-24) the document declares."""
    try:
        tree = ET.parse(path)
    except ET.ParseError as e:
        return None, 'not well-formed XML: %s' % e
    for node in tree.iter(RAM + 'GuidelineSpecifiedDocumentContextParameter'):
        ident = node.find(RAM + 'ID')
        if ident is not None and ident.text:
            return ident.text.strip(), None
    return None, 'no GuidelineSpecifiedDocumentContextParameter/ID (BT-24) in the document'


def svrl_hits(report, tag):
    """Collect one kind of Schematron hit - failed-assert or successful-report - from an SVRL report."""
    hits = []
    for m in re.finditer(r'<svrl:%s\b([^>]*)>(.*?)</svrl:%s>' % (tag, tag), report, re.S):
        attrs, body = m.group(1), m.group(2)
        rule = re.search(r'id="([^"]*)"', attrs)
        flag = re.search(r'flag="([^"]*)"', attrs)
        text = re.search(r'<svrl:text>(.*?)</svrl:text>', body, re.S)
        # A <report> carries neither id nor flag: name it by the element its location ends on, which
        # is what tells one "marked as not used" apart from the next.
        location = re.search(r'location="([^"]*)"', attrs)
        steps = re.findall(r'\*:([A-Za-z]+)\[', location.group(1)) if location else []
        hits.append({
            'rule': rule.group(1) if rule else (steps[-1] if steps else '?'),
            'flag': flag.group(1) if flag else '-',
            'text': ' '.join((text.group(1) if text else '').split())[:160],
        })
    return hits


def run_xslt(xslt, xml, out):
    """Apply one Schematron XSLT and return the assertions that failed and the reports that fired."""
    r = subprocess.run(['java', '-jar', SAXON_JAR, '-s:' + xml, '-xsl:' + xslt, '-o:' + out],
                       capture_output=True, text=True)
    if r.returncode != 0:
        return None, r.stderr.strip().splitlines()[:3]
    report = open(out, encoding='utf-8').read()
    return {
        'checks': len(re.findall(r'<svrl:fired-rule\b', report)),
        'fails': svrl_hits(report, 'failed-assert'),
        'reports': svrl_hits(report, 'successful-report'),
    }, None


def xsd_validate(xml):
    """Validate against the CII D22B schema, through PHP: it is what the module runs on anyway."""
    script = ('$d=new DOMDocument();libxml_use_internal_errors(true);$d->load($argv[1]);'
              'if($d->schemaValidate($argv[2])){echo "OK";}'
              'else{foreach(libxml_get_errors() as $e){echo trim($e->message)."\\n";}}')
    r = subprocess.run([PHP_BIN, '-r', script, xml, XSD], capture_output=True, text=True)
    out = r.stdout.strip()
    return (out == 'OK'), (out if out else r.stderr.strip())


def validate(path, strict, quiet, reportdir):
    """Validate one document. Returns (ok, text)."""
    base = os.path.basename(path)
    urn, err = guideline(path)
    if err:
        return False, '%-40s SKIP  %s' % (base, err)
    if urn not in PROFILES:
        return False, '%-40s SKIP  unknown specification identifier "%s"' % (base, urn)
    if PROFILES[urn] is None:
        return False, '%-40s SKIP  the FNFE package ships no validator for "%s"' % (base, urn)

    folder, profile_xslt = PROFILES[urn]
    brfr = 'BR-FR-Flux2-Schematron-CII.xslt' if strict else 'BR-FR-Flux2-Schematron-CII_WARNING.xslt'
    stages = [
        ('profile ' + profile_xslt, os.path.join(FNFE_ROOT, folder, profile_xslt)),
        ('ctc-fr  ' + brfr, os.path.join(FNFE_ROOT, folder, brfr)),
    ]

    ok_xsd, xsd_msg = xsd_validate(path)
    lines = ['  xsd     CrossIndustryInvoice_100pD22B      : ' + ('valid' if ok_xsd else 'INVALID')]
    if not ok_xsd:
        lines.extend('      ' + m for m in xsd_msg.splitlines()[:5])

    valid = ok_xsd
    for label, xslt in stages:
        if not os.path.exists(xslt):
            lines.append('  %-42s: MISSING (%s)' % (label, xslt))
            valid = False
            continue
        res, run_err = run_xslt(xslt, path, os.path.join(reportdir, base + '.' + os.path.basename(xslt) + '.svrl'))
        if run_err:
            lines.append('  %-42s: ERROR %s' % (label, ' '.join(run_err)))
            valid = False
            continue
        count = '%s check(s), %s failure(s)' % (res['checks'], len(res['fails']))
        if res['reports']:
            count += ', %s report(s)' % len(res['reports'])
        lines.append('  %-42s: %s' % (label, count))
        lines.extend('      [%s] (%s) %s' % (f['rule'], f['flag'], f['text'])
                     for f in res['fails'] + res['reports'])
        if res['fails'] or res['reports']:
            valid = False

    text = '%-40s %s   %s' % (base, 'VALID  ' if valid else 'INVALID', urn)
    if not quiet:
        text += '\n' + '\n'.join(lines)
    return valid, text


def main():
    args = sys.argv[1:]
    strict = '--strict' in args
    quiet = '--quiet' in args
    files = [a for a in args if not a.startswith('--')]

    if not files:
        die('usage: validate-cii.py [--quiet] [--strict] file.xml [file.xml ...]')
    if not FNFE_ROOT or not os.path.isdir(FNFE_ROOT):
        die('FNFE_ROOT does not point at a FNFE_RFE_INVOICE directory (got "%s")' % FNFE_ROOT)
    if not SAXON_JAR or not os.path.exists(SAXON_JAR):
        die('SAXON_JAR does not point at a Saxon jar (got "%s")' % SAXON_JAR)
    if not os.path.exists(XSD):
        die('the CII D22B schema is not in FNFE_ROOT (looked for %s)' % XSD)

    reportdir = os.environ.get('SVRL_DIR', 'svrl-reports')
    os.makedirs(reportdir, exist_ok=True)

    all_ok = True
    for path in files:
        if not os.path.exists(path):
            print('%-40s SKIP  no such file' % os.path.basename(path))
            all_ok = False
            continue
        ok, text = validate(path, strict, quiet, reportdir)
        print(text)
        all_ok = ok and all_ok

    return 0 if all_ok else 1


if __name__ == '__main__':
    sys.exit(main())
