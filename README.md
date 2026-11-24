# Preservica Connector

An [Omeka S](https://omeka.org/s/) module that searches a
[Preservica](https://preservica.com/) digital preservation system from the
Omeka admin and imports the assets you pick, or whole folders, as Omeka items,
ready to use in sites and exhibits.

For each selected asset the module:

- creates one Omeka item,
- maps the asset's Dublin Core and MODS metadata to Dublin Core Terms
  properties,
- either copies the asset's access files into Omeka as ordinary media, or
  links the item to the asset's IIIF manifest on your discovery platform, so
  that no files are copied (see "Linking to IIIF manifests"),
- remembers which asset the item came from, so the same asset is not imported
  twice.

Imports run as background jobs and can be undone.

## Requirements

- Omeka S 4.0 or later
- A Preservica system whose edition includes API access (Access Token,
  Content and Entity APIs)
- A Preservica user account for the module to log in with. Use a dedicated
  account that
  - has **read-only** access to just the content Omeka should be able to
    import, and
  - does **not** use two-factor authentication (the module logs in
    unattended).

## Installation

See the general documentation on
[installing a module](https://omeka.org/s/docs/user-manual/modules/#installing-modules).

1. Unzip the release into the `modules` directory of Omeka S. The directory
   must be named `PreservicaConnector`.
2. In the Omeka admin, go to **Modules** and click **Install** next to
   Preservica Connector.

## Configuration

Go to **Modules → Preservica Connector → Configure**.

| Setting | Notes |
| --- | --- |
| Preservica host | For example `us.preservica.com`, `eu.preservica.com`, or the address of your own server. |
| Username, Password | The service account. The stored password is never shown again; leave the field blank to keep it. |
| Tenant | Optional. Only needed if the account belongs to more than one tenant. |
| Use preservation files when there is no access copy | On by default. When off, assets without an Access representation are imported as metadata only. |
| Record the Preservica reference | On by default. Adds the asset's reference (UUID) to the item as a Dublin Core Identifier. |
| Media for imported items | **Copy files** (the default) or **Link to IIIF manifests**; see below. |
| Shared identifier type | For linking: the type of the Preservica external identifier that holds each asset's shared identifier. |
| IIIF manifest URL pattern | For linking: where the discovery platform serves an item's manifest, with `{id}` for the shared identifier, e.g. `https://discovery.example.edu/iiif/{id}/manifest`. |
| Discovery record URL pattern | Optional: the item's page in the discovery site, e.g. `https://discovery.example.edu/catalog/{id}`. |

When you save, the module logs in to Preservica once and tells you whether
the connection works.

If you get an error message during configuration, check the proxy settings in config/local.coonfig.php, specifically the value for 'http_client,' which is located in the Omeka-S install location. It will look like the following:

    'http_client' => [
...
'proxy_host' => 'proxy_url',
'proxy_port' => ##,
    ],

There's additional lines that might need to be configured under the proxy information, like a user and password for authentication if it's required. 

## Importing

1. Go to **Preservica Connector → Search and import**.
2. Enter search terms. The search covers everything Preservica indexes for
   assets; enter `%` to list every asset the service account can read.
3. Tick the assets to import. Assets that are already in Omeka are linked to
   their item and cannot be selected again.
4. Optionally choose an item set and add a comment, then click
   **Import selected**.

The selection covers the page of results you are looking at; show up to 100
results per page to import more in one go.

### Importing a whole folder

1. Go to **Preservica Connector → Browse folders** and open the folder you
   want, for example a collection. The path at the top takes you back up.
2. Under **Import the folder**, leave **Include subfolders** ticked to import
   everything below the folder, at any depth, or untick it for just the
   assets directly in it.
3. **Add to item set** defaults to an item set named after the folder, with
   the folder's description. All the assets, including those in subfolders,
   go into that one item set. It is created the first time the folder is
   imported and reused after that. You can choose an existing item set or no
   item set instead.
4. Click **Import folder**.

Assets that are already in Omeka are not imported again, but they are added
to the chosen item set, so the item set ends up holding the whole folder.
Importing the same folder again later picks up only assets added to
Preservica since. Large folders are fine: the job reads the folder from
Preservica 100 entries at a time.

You can also tick individual assets in a folder and import just those.

### Linking to IIIF manifests

When your discovery platform serves IIIF manifests, set **Media for imported
items** to **Link to IIIF manifests**. Each imported item then gets the
asset's manifest from the platform as its media, and no files are copied
into Omeka; Omeka keeps only the manifest data and a thumbnail. There is one
copy of each file, and a rights change on the platform applies to exhibits
too.

The module finds the manifest through the asset's shared identifier: the
Preservica external identifier of the configured type (the type is matched
without regard to capitals or spacing). It puts that identifier into the
`{id}` of the URL pattern, encoded for use in a web address. The item also
gets the shared identifier as a Dublin Core Identifier and, if a record URL
pattern is set, a Relation linking to "Discovery record".

An asset without a shared identifier, or whose manifest the platform does not
serve, is not imported; the job log says why. Nothing is copied as a
fallback.

**Switching earlier imports.** Items imported while copying files can be
switched to links later: on **Past imports**, tick the imports and click
**Switch to IIIF links** (shown only when linking is on). Each item keeps its
Omeka id, metadata and item sets, gets the manifest added, and loses the
copied files. Exhibit pages that feature an item keep working, but a page
block that shows one specific copied file needs that file replaced by the
new IIIF media. If an item's manifest cannot be added, its files are kept.
Switching again is safe; items that are already linked are left alone.

The media use the module's own **IIIF presentation (Preservica Connector)**
ingester and display with Omeka's IIIF presentation viewer. It accepts IIIF
Presentation 3 manifests whose `@context` is a list, which Presentation 3
requires when a manifest uses extensions and which Omeka S core (4.1 and 4.2)
rejects.

### Past imports and undo

**Preservica Connector → Past imports** lists every import with its results
and a link to the items it created. The job log (click the job number) has
one line per asset, including the reason for any failure. To remove the
items and files an import created, tick it and click
**Undo selected imports**. Undoing a folder import also deletes the folder's
item set if the import created it and nothing else is left in it. Nothing is
ever changed in Preservica.

You can also attach a Preservica file to an existing item: on the item's
**Media** tab choose **Preservica** and enter an asset reference.

### What is imported

**Metadata.** Dublin Core and MODS are read from all of the asset's metadata
fragments and merged; a value that appears in both is stored once. If neither
gives a title or description, the asset's own title and description in
Preservica are used. Other schemas (EAD, custom schemas) are not mapped.

*Dublin Core.* Every `dc:` (Dublin Core Elements 1.1) and `dcterms:` element
is added to the Dublin Core Terms property of the same name, for example
`dc:creator` becomes `dcterms:creator`. Values that are web addresses are
stored as URIs.

*MODS.* MODS version 3 records are mapped to Dublin Core Terms following the
Library of Congress MODS-to-Dublin-Core crosswalk:

| MODS | Dublin Core Terms |
| --- | --- |
| `titleInfo` (with non-sort, subtitle, part number and name) | Title; Alternative Title if the `titleInfo` has a `type` |
| `name` | Creator if it has no role, a creator or author role, or `usage="primary"`; otherwise Contributor |
| `typeOfResource`, `genre` | Type |
| `originInfo/publisher` | Publisher |
| `originInfo` dates (`dateCreated`, `dateIssued`, `dateCaptured`, `dateOther`) | Date; a start and end pair becomes `start/end` |
| `originInfo/copyrightDate` | Date Copyrighted |
| `language/languageTerm` | Language |
| `physicalDescription/extent` | Extent |
| `physicalDescription/form`, `internetMediaType` | Format |
| `abstract`, `note` | Description |
| `tableOfContents` | Table Of Contents |
| `targetAudience` | Audience |
| `subject` | Subject; compound headings are joined with `--`. A subject that is only a place becomes Spatial Coverage, only a time period Temporal Coverage |
| `classification` | Subject |
| `relatedItem` | Is Part Of for `type="host"` or `"series"`, Source for `type="original"`, otherwise Relation |
| `identifier`, `location/url` | Identifier |
| `accessCondition` | Rights |

Where MODS links a name, subject, genre or language to an authority with
`valueURI`, or a rights statement with `xlink:href`, the value is stored as a
URI with the text as its label. Place of publication, physical location and
`recordInfo` are not imported.

**Files.** The module uses the asset's Access representation, or its
Preservation representation if there is none and the fallback is enabled. Each
content object in that representation becomes one media, taken from its
active generation, in the order Preservica lists them. Files are checked
against Omeka's allowed media types and extensions like any upload
(**Settings → Security**).

An asset whose file cannot be downloaded or is not allowed by Omeka is not
imported at all; it is counted as failed and can be selected again later.

Imports are a one-time copy. Later changes in Preservica are not carried
over; undo the import and import again to refresh.

## Permissions and security

- Global administrators, site administrators and editors can search and
  import. Anyone with that access can see and import everything the service
  account can read, so scope the account's Preservica permissions
  accordingly.
- The service account password is stored in Omeka's `setting` table in
  plain text, like other Omeka settings. Protect database backups and use an
  account that can do nothing but read.
- The module only sends requests to the configured host.

## Development

`test/` contains a stub of the Preservica API (`mock-preservica.php`, with a
small folder tree) and scripts that drive a real Omeka S install against it:
`e2e.py` (install, configure, search, import, re-import, folder import, undo),
`browser.py` and `browser-folders.py` (the same flows in headless Chrome), and
a unit test of the MODS crosswalk (`php test/mods-mapper-test.php`). The directory is left out of release zips.

To publish a release on omeka.org, tag the version set in `config/module.ini`
and attach a zip built with:

    git archive --output=PreservicaConnector-{version}.zip --prefix=PreservicaConnector/ {tag}

## License

GPL-3.0-or-later. See `LICENSE`.
