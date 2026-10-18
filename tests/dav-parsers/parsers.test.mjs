// The Files app of each NC 31-35 release reads PROPFIND answers with the
// webdav + fast-xml-parser it bundles; package.json pins every pair that was
// shipped (taken from the package-lock.json of all release tags). Each must read
// nc:metavox as the server writes it (tests/fixtures/dav-metavox.xml, kept up to
// date by MetadataPropertyXmlTest) and random CDATA back unchanged.
//
//   npm run test:dav-parsers   (from the app root)
//
// A new NC release with another pair: add it to package.json under the same name scheme.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const PAIRS = Object.keys(JSON.parse(read('./package.json')).devDependencies)
const { files } = JSON.parse(read('../fixtures/dav-metavox.json'))
const SERVER_XML = read('../fixtures/dav-metavox.xml')
const MAX_CHARS = 512

function installedVersion(pkg, from) {
	for (const dir of [`node_modules/${from}/node_modules/${pkg}`, `node_modules/${pkg}`]) {
		try {
			return JSON.parse(read(`./${dir}/package.json`)).version
		} catch {}
	}
	return null
}

// As the Files app lists a folder: the first propstat's props of each response.
async function list(pair, xml) {
	const { parseXML, prepareFileFromProps } = await import(pair)
	const result = await parseXML(xml)
	return [].concat(result.multistatus.response).map((item) => ({
		href: decodeURIComponent(item.href),
		metavox: prepareFileFromProps([].concat(item.propstat)[0].prop, item.href, true).props.metavox,
	}))
}

// The same rules as MetadataPropertyXmlTest::assertReadsBack().
function assertValues(file, text) {
	if (file.noValues) {
		assert.equal(text, '{"v":1}')
		return
	}
	assert.equal(typeof text, 'string')
	const doc = JSON.parse(text)
	assert.equal(doc.v, 1)
	assert.equal(doc.g, file.groupfolder)
	assert.equal(doc.e === 1, file.canWrite)
	const kept = Object.entries(file.values).filter(([, value]) => value !== null && value !== '')
	assert.deepEqual(Object.keys(doc.f ?? {}).sort(), kept.map(([name]) => name).sort())
	for (const [name, value] of kept) {
		if ([...value].length > MAX_CHARS) {
			assert.ok(doc.t.includes(name), `${name} listed as cut off`)
			assert.ok(doc.f[name] !== '' && value.startsWith(doc.f[name]), `${name} is a prefix`)
		} else {
			assert.equal(doc.f[name], value, `${name} of ${file.href}`)
		}
	}
}

// JSON the way MetadataPropertyPayload writes it: ASCII only, < and > escaped.
const phpJson = (value) => JSON.stringify(value).replace(/[\u0080-\uffff<>]/g, (c) => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0').toUpperCase())

function randomListing(count) {
	const pieces = [']]>', ']]', ']', '>', '<', '<![CDATA[', '-->', '&', '&amp;', '&lt;', '&#0;', '"', "'", '\\', '\\u003E', '/', ';#',
		'\t', '\n', '\r', '\x00', '\x01', '\x0b', '\x1f', '\x7f', ' ', 'a', 'Z', '0', '007', '-1e5', 'true', '\u00e9', '\u20ac', '\u6570',
		'\u{1F600}', '\ufffe', '\u2028', '\u200b', '{"', '"}']
	let seed = 8
	const random = (n) => {
		seed = (seed * 1103515245 + 12345) % 2147483648
		return seed % n
	}
	const texts = []
	for (let i = 0; i < count; i++) {
		let value = ''
		for (let j = random(30); j > 0; j--) value += pieces[random(pieces.length)]
		texts.push(phpJson({ v: 1, g: i, f: { a: value } }))
	}
	const responses = texts.map((text, i) => `<d:response><d:href>/remote.php/dav/files/alice/f${i}</d:href><d:propstat><d:prop>`
		+ `<nc:metavox><![CDATA[${text}]]></nc:metavox></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>`)
	const xml = '<?xml version="1.0"?>\n<d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">' + responses.join('') + '</d:multistatus>'
	return { texts, xml }
}

describe('nc:metavox through every Files-app parser of NC 31-35', () => {
	const random = randomListing(2000)

	for (const pair of PAIRS) {
		describe(pair, () => {
			it('is installed with the parser it is named after', () => {
				const [, webdav, parser] = pair.match(/^webdav-(.+)-fxp-(.+)$/)
				assert.equal(JSON.parse(read(`./node_modules/${pair}/package.json`)).version, webdav)
				assert.equal(installedVersion('fast-xml-parser', pair), parser)
			})

			it('reads the server\'s output back as the values', async () => {
				const listed = await list(pair, SERVER_XML)
				assert.deepEqual(listed.map((node) => node.href), files.map((file) => file.href))
				files.forEach((file, i) => assertValues(file, listed[i].metavox))
			})

			it('reads random CDATA back unchanged', async () => {
				const listed = await list(pair, random.xml)
				assert.equal(listed.length, random.texts.length)
				listed.forEach((node, i) => assert.equal(node.metavox, random.texts[i]))
			})
		})
	}
})
