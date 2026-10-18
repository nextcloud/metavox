import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { parseXML, prepareFileFromProps } from 'webdav'
import { decodeNode } from '../../src/filesplugin/columns/DavMetadata.js'

// The Files app reads listings with the webdav library (fast-xml-parser), which
// turns bare numeric text into numbers. These tests run a multistatus shaped
// like Sabre's output through the same steps as webdav's directory listing.

const xmlText = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

function response(href, props, missing = '') {
	return `<d:response><d:href>${href}</d:href>`
		+ `<d:propstat><d:prop>${props}<d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>`
		+ (missing ? `<d:propstat><d:prop>${missing}</d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>` : '')
		+ '</d:response>'
}

async function listNodes(responses) {
	const xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns" '
		+ `xmlns:oc="http://owncloud.org/ns">${responses.join('')}</d:multistatus>`
	const result = await parseXML(xml)
	// As webdav's getDirectoryFiles(): the first propstat's props of each response.
	return result.multistatus.response.map((item) => ({ attributes: prepareFileFromProps(item.propstat.prop, item.href, true).props }))
}

describe('nc:metavox through the Files app parser', () => {
	it('arrives as a string and decodes to the original values', async () => {
		// As PHP json_encode writes it: non-ASCII as \u escapes.
		const json = '{"v":1,"g":12,"e":1,"f":{"version":"2024.10","count":"007","markup":"&<\\"x\\">","name":"\\u00e9\\u20ac"}}'

		const [node] = await listNodes([response('/remote.php/dav/files/alice/HR/a.pdf', `<oc:fileid>12</oc:fileid><nc:metavox>${xmlText(json)}</nc:metavox>`)])

		expect(typeof node.attributes.metavox).toBe('string')
		expect(node.attributes.fileid).toBe(12)
		expect(decodeNode(node)).toEqual({
			gf: 12,
			canWrite: true,
			values: { version: '2024.10', count: '007', markup: '&<"x">', name: 'é€' },
			truncated: [],
		})
	})

	it('reads a CDATA section the same, without decoding entities inside it', async () => {
		// As the server writes it: one CDATA section, < and > as \u escapes (JSON_HEX_TAG).
		const json = '{"v":1,"g":12,"e":1,"f":{"markup":"&amp;\\u003Cb\\u003E\\"x\\"","end":"]]\\u003E","count":"007"}}'

		const [node] = await listNodes([response('/remote.php/dav/files/alice/HR/a.pdf', `<oc:fileid>12</oc:fileid><nc:metavox><![CDATA[${json}]]></nc:metavox>`)])

		expect(node.attributes.metavox).toBe(json)
		expect(decodeNode(node).values).toEqual({ markup: '&amp;<b>"x"', end: ']]>', count: '007' })
	})

	it('reads the server\'s exact output back as the values', async () => {
		// Written by MetadataPropertyXmlTest; tests/dav-parsers reads it with the parsers of NC 31-35.
		const { files } = JSON.parse(readFileSync('tests/fixtures/dav-metavox.json', 'utf8'))
		const result = await parseXML(readFileSync('tests/fixtures/dav-metavox.xml', 'utf8'))
		const nodes = result.multistatus.response.map((item) => ({ attributes: prepareFileFromProps(item.propstat.prop, item.href, true).props }))

		files.forEach((file, i) => {
			const node = decodeNode(nodes[i])
			if (file.noValues) return expect(node).toEqual({ gf: null, canWrite: false, values: {}, truncated: [] })
			const kept = Object.entries(file.values).filter(([, value]) => value !== null && value !== '')
			expect(node.gf).toBe(file.groupfolder)
			expect(node.canWrite).toBe(file.canWrite)
			for (const [name, value] of kept) {
				if (node.truncated.includes(name)) expect(value.startsWith(node.values[name])).toBe(true)
				else expect(node.values[name]).toBe(value)
			}
			expect(Object.keys(node.values).sort()).toEqual(kept.map(([name]) => name).sort())
		})
	})

	it('is unknown when the server answered 404 for it', async () => {
		const [node] = await listNodes([response('/remote.php/dav/files/alice/a.pdf', '<oc:fileid>13</oc:fileid>', '<nc:metavox/>')])

		expect(node.attributes.metavox).toBeUndefined()
		expect(decodeNode(node)).toBeNull()
	})

	it('reads the folder entry of a team folder root', async () => {
		const [node] = await listNodes([response('/remote.php/dav/files/alice/HR/', '<nc:metavox>{"v":1,"g":12}</nc:metavox>')])

		expect(decodeNode(node)).toEqual({ gf: 12, canWrite: false, values: {}, truncated: [] })
	})
})
