# CMS Tree Page View – Reorder Pages with a Drag-and-Drop Tree

Contributors: eskapism
Donate link: https://eskapism.se/sida/donate/
Tags: reorder pages, page order, drag-and-drop, custom post types, tree view
Text Domain: cms-tree-page-view
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.5.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See every page as a drag-and-drop tree. Reorder your site structure in seconds — then edit, add and search right there.

## Description

**Your whole site, laid out as one clear tree. Drag a page to move it, click to edit, and shape your structure in seconds.**

See the tree in action:

https://youtu.be/UV47jXEB-r8

CMS Tree Page View gives WordPress the page overview it's always been missing: one friendly tree of every page and post, so you can finally see how your site fits together — and rearrange it just by dragging.

No more clicking through endless paginated lists. Everything's in front of you: drag a page to reorder or renest it, and edit, add or search right where you are. Setting up a brand-new site? Add a whole batch of pages at once.

Loved by editors, agencies and anyone who looks after a content-heavy site — and actively maintained again by its original author, in use since 2010 and translated into more than 20 languages.

If you also run [Simple History](https://simple-history.com/?utm_source=cms-tree-page-view&utm_medium=plugin&utm_campaign=cmstpv_readme) — my free activity-log plugin — the tree shows recent changes as you go: pick any page to see its history, or glance at the latest edits across your whole site, without leaving the screen.

#### Why you'll like it

- **See your whole site in one place.** Every page, and exactly how it's nested, in a single tree.
- **Rearrange by dragging.** Move a page up, down, or into another page just by dragging it — the order sticks, and your theme can use it too.
- **Do everything without leaving the tree.** Edit, view, add and search your pages right where you see them.
- **Not just pages.** Turn on the tree for posts, products or any content type — and drag an item from one tree into another to change what kind of thing it is.

#### What's in the tree

- A clear, visual tree of your pages and posts — like folders in Finder or File Explorer
- Drag and drop to reorder and renest
- Drag a brand-new page straight into the tree to create it exactly where it belongs
- Add a page after or inside another — or a whole batch at once
- Edit or view any page straight from its row: hover a page and the icons appear, or press "e" or "v"
- See a live preview of any page right beside the tree, without opening it
- Search the whole tree without leaving the screen
- Full keyboard navigation, so your hands can stay on the keys
- A tree right on your dashboard, ready the moment you log in
- See who moved, edited or added a page, right beside the tree, plus a history icon on every row — when the free Simple History plugin is installed
- Works with pages, posts, products and any custom content type — hierarchical or not

#### Showing this order on your site

The plugin stores the order you set as WordPress' "menu order". It does not change how your
theme outputs your pages — to show them on the front end in the tree order, the query that
outputs them must sort by `menu_order`. See the FAQ below for ready-to-use code examples.

#### Translations/Languages

Available in 20+ languages, including German, French, Spanish, Russian, Italian, Dutch, Polish, Swedish, Greek, Finnish and Japanese.

#### Always show your pages in the admin area

If you want to always have a list of your pages available in your WordPress admin area, please check out the plugin
[Admin Menu Tree Page View](https://wordpress.org/plugins/admin-menu-tree-page-view/).

#### Donation and more plugins

- These two plugins work nicely together: install my free [Simple History](https://simple-history.com/?utm_source=cms-tree-page-view&utm_medium=plugin&utm_campaign=cmstpv_readme) plugin and the tree will show you who recently moved, edited or added each page. On its own, Simple History is a complete activity log for your admin — logins (both failed and successful), post and page edits, plugin updates, user changes and more — so you can always see what happened on your site, and when.
- If you like this plugin don't forget to [donate to support further development](https://eskapism.se/sida/donate/).

## Installation

1. Upload the folder "cms-tree-page-view" to "/wp-content/plugins/"
1. Activate the plugin through the "Plugins" menu in WordPress
1. Done!

Now the tree with the pages will be visible both on the dashboard and in the menu under pages.

## Frequently Asked Questions

### I reordered my pages but the new order does not show on my website. Why?

CMS Tree Page View saves the order you set as WordPress' "menu order". It does not change how your theme or other plugins query and display your pages — that is up to the theme. To show your pages in the same order on the front end, the query that outputs them must sort by `menu_order`. For example:

`$query = new WP_Query( array(
	'post_type'      => 'page',
	'orderby'        => 'menu_order',
	'order'          => 'ASC',
	'posts_per_page' => -1,
) );`

For `wp_list_pages()` or a navigation menu, use `'sort_column' => 'menu_order'`. Many themes sort by title or date by default, which is why the tree order may not appear automatically.

### The tree does not load, or keeps showing "Loading..."

This is almost always a JavaScript error from another plugin or your theme that stops the tree from initializing. To track it down:

1. Open your browser's developer console (F12, then the Console tab) on the tree page and look for red errors.
2. Temporarily switch to a default theme such as Twenty Twenty-Four.
3. Deactivate your other plugins one by one to find the conflict.

### Which post types can use the tree?

Any public post type. By default the tree is enabled for hierarchical post types (such as Pages). You can enable or disable it per post type under Settings &rarr; CMS Tree Page View.

### Can I reorder posts, products or other custom post types?

Yes. The tree works with any public post type — pages, regular posts, WooCommerce products, and your own custom post types — as long as you enable it for that post type in the settings. It works with both hierarchical and non-hierarchical post types, and you can even drag an item from one tree into another to change its post type.

### Which pages and posts can each user see in the tree?

The same ones they can see on WordPress' built-in Posts and Pages screens. The tree follows WordPress' own permissions, so each user only sees content they are allowed to manage. For example, a user who can edit only their own content (such as a Contributor) sees their own pages and posts in the tree, not other people's drafts — exactly as on the standard WordPress overview screens.

### Can I see who changed a page, or a history of edits?

Yes — if you also install the free [Simple History](https://wordpress.org/plugins/simple-history/) plugin. When it's active, CMS Tree Page View shows recent activity right in the tree: select any page to see its own history — who edited it, and when — or open the "Latest changes" feed to see edits, moves and new pages across your whole site at a glance. Simple History is a separate free plugin by the same author; the tree works fine without it, and simply hides the history panels when it isn't installed.

### Will the tree change or slow down the front end of my site?

No. CMS Tree Page View only adds the tree inside the WordPress admin; it loads nothing on the public side of your site, so it has no effect on front-end performance. The only thing it stores is the page order (as WordPress' "menu order") — see the first FAQ for how to display that order on the front end.

### Does it work with the block editor (Gutenberg)?

Yes. CMS Tree Page View uses WordPress' own edit links, so clicking "Edit" on a page opens it in whatever editor your site uses — the block editor (Gutenberg) or the classic editor. The tree itself works independently of the editor you have active.

### Is the plugin maintained?

Yes. CMS Tree Page View has been taken back over by its original author and is being actively maintained again.

### Can I reorder pages on a phone or tablet?

Yes — select a page and use the Move up / Move down buttons in the page's detail card.

Dragging a page to a _different_ level in the hierarchy (making it a child of another page) needs a mouse: it uses the browser's native drag and drop, which touch screens do not support.

## Screenshots

1. Your entire site structure at a glance — every page nested as a tree, with a live preview of the page you select.
2. Select any page to edit, view, add a child, or reorder it — right from the tree.
3. Add several pages at once — type one title per line, as drafts or published.
4. Find any page instantly with built-in search.
5. Drag and drop to reorder pages — the new order is saved automatically.
6. See who moved, edited or added each page, right beside the tree — when the free Simple History plugin is installed.
7. The tree on your dashboard, ready the moment you log in.
8. Switch between the regular list view and the tree view in one click.

## Changelog

### 2.5.2 (August 2026)

#### Fixed

- A custom post type's tree could fail to load when a theme or plugin's own routing reacted to `post_type` in the URL; the REST API now uses non-colliding parameter names.

### 2.5.1 (August 2026)

#### Fixed

- Sites running Simple History older than 5.27.0 no longer hit a fatal error on every page tree screen.
- The Simple History logger is no longer registered on Simple History 3.x and older, where loading it crashed the site.

### 2.5.0 (August 2026)

#### Added

- The page details panel now shows a live preview of the selected page, rendered at desktop width.
- "Simple History" in the page history heading now links to simple-history.com.

#### Fixed

- A long page title in the dashboard widget no longer leaves the page icon, padlock and status badge stranded halfway down the row.
- A page title with no spaces in it, such as a pasted URL, no longer stretches the tree screen sideways or pushes the dashboard widget's badges off the card.
- Preview on a page of a non-public post type no longer opens a WordPress admin screen instead of the page.
- The tree no longer stretches across the whole screen on a wide monitor, leaving the page details stranded far to the right.
- The buttons on the page details panel now fit on one row on a laptop, instead of pushing the reorder arrows onto a second line.
- A narrow browser window no longer squeezes the tree down to a couple of hundred pixels.

### 2.4.1 (August 2026)

#### Changed

- Posts can no longer be dropped inside other posts, which WordPress itself gives you no way to do.
- Posts nested by an earlier version still show that way in the tree, and can be dragged back out.

#### Fixed

- Reordering by drag-and-drop now works in the tree of a non-hierarchical custom post type.
- Dragging a new page in from the "Add pages" box works there too.
- The tree no longer loads forever when the server doesn't answer; it now says so and offers a retry.

### 2.4.0 (August 2026)

#### Added

- Edit and View icons on every row of the tree and the dashboard widget, shown when you hover or focus a page.
- A page-history icon on each row when Simple History is installed, opening that page's own history.
- Drafts and pending pages offer Preview where a published page offers View.
- Press `e` or `v` on the focused page to edit or view it.
- Page builders and other plugins can add their own row icon through the `cms_tree_page_view_post_edit_links` filter.
- A one-time tip after your first reorder suggests Simple History for tracking who changed what, if it is not already installed.

#### Changed

- Pages you cannot edit show their title in a muted grey, so you can see what is yours without clicking.
- The selected page's card now makes the full case for Simple History when it is missing, naming the page you picked.

#### Fixed

- A page you cannot edit no longer shows a pointer cursor as though its title were a link.

### 2.3.1 (August 2026)

#### Added

- Password-protected pages are marked with a padlock again, in the tree, the dashboard widget, and the page details card.

#### Fixed

- Users who can only edit their own pages see the tree again. It lists every published page plus their own drafts, instead of coming up empty.
- The status filter tabs count the pages the tree actually shows, so a tab can no longer say "All (18)" above an empty tree.
- Page titles in the tree no longer carry the front-end "Protected:" and "Private:" prefixes.
- The tree no longer offers a drag or an "Add page after" on a page you cannot edit, where the action could only fail.

#### Security

- Adding a page after another page now requires permission to edit that page, since it renumbers the pages around it.

### 2.3.0 (August 2026)

#### Changed

- The whole "Open full tree" footer in the dashboard widget is now clickable, including the line of text under it.

#### Fixed

- Dashboard widget links no longer fail with "Sorry, you do not have permission to access this page" for a post type that is enabled on the dashboard but not in the menu.

#### Security

- The tree's REST API now answers only for the post types the tree shows. A crafted request for a revision, attachment, or other internal post type no longer returns page details or accepts a move.

### 2.2.0 (July 2026)

#### Fixed

- Right-click (or ⌘/Ctrl/middle-click) a page title in the tree to open its editor in a new tab again — titles are real links once more.

### 2.1.0 (July 2026)

#### Added

- "Edit in Elementor" is back — pages built with Elementor show an editor link in the tree's detail card.
- New `cms_tree_page_view_post_edit_links` filter lets any page builder add its own edit link to the tree.

#### Fixed

- The tree view is now usable on phones and small screens — it no longer overflows sideways or stretches into an endlessly tall page, and tapping a page brings its details into view.

### Older versions

The changelog for all previous releases (1.7.1 and earlier, back to the first release in 2010) is in [changelog.txt](changelog.txt), included with the plugin.

ysaetf7ruhjnm3e2x4tbtletpc35ckeb
