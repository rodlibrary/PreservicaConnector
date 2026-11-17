# Preservica Connector

An [Omeka S](https://omeka.org/s/) module that searches a
[Preservica](https://preservica.com/) digital preservation system from the
Omeka admin and imports the assets you pick as Omeka items, ready to use in
sites and exhibits.

For each selected asset the module:

- creates one Omeka item,
- maps the asset's Dublin Core and MODS metadata to Dublin Core Terms
  properties,
- copies the asset's access files into Omeka as ordinary media, so pages keep
  working without a live connection to Preservica,
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

When you save, the module logs in to Preservica once and tells you whether
the connection works.

If you get an error message during configuration, check the proxy settings in local.config.ini, specifically the value for 'http_client,' which is located in the Omeka-S install location. It will look like the following:

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

**Preservica Connector → Past imports** lists every import with its results
and a link to the items it created. The job log (click the job number) has
one line per asset, including the reason for any failure. To remove the
items and files an import created, tick it and click
**Undo selected imports**. Nothing is ever changed in Preservica.

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

`test/` contains a stub of the Preservica API (`mock-preservica.php`) and two
scripts that drive a real Omeka S install against it, plus a unit test of
the MODS crosswalk (`php test/mods-mapper-test.php`): `e2e.py` (install,
configure, search, import, re-import, undo) and `browser.py` (the same flow in
headless Chrome). The directory is left out of release zips.

To publish a release on omeka.org, tag the version set in `config/module.ini`
and attach a zip built with:

    git archive --output=PreservicaConnector-{version}.zip --prefix=PreservicaConnector/ {tag}

## License

GPL-3.0-or-later. See `LICENSE`.
