# Highlight Rules

Highlight rules color cells in the file list by their value: a deadline that has passed in red, a missing owner in orange, an approved document in green. The rules are part of a [view](views.md), so everyone who uses that view sees the same highlights.

Highlights only change how the list looks. They do not change files or metadata, and they do not hide anything. To show only some files, use a filter.

## What you see

A highlighted cell gets a soft background in the rule's color, a bar on its leading edge, and an icon. Each color has its own icon, so a highlight never depends on color alone:

| Color | Icon | Typical use |
|---|---|---|
| Red | Badge with an exclamation mark | Something is wrong or late |
| Orange | Warning triangle | Needs attention |
| Green | Check mark | Done, in order |
| Blue | "i" | For information |

- The exact shade follows your Nextcloud theme, dark mode included.
- A column that has rules shows a palette icon next to its name in the column header.
- Point at the icon to see the highlight's text, for example what needs to happen: *Approve first*. A screen reader reads that text with the cell, for example "Deadline: 3 October 2026, Overdue."
- Highlights appear in the list layout of the Files app. The tile (grid) layout shows none.
- Rules about today, such as **Before today**, follow the calendar: at midnight the cells are checked again, also when the page stays open.

To see which rules a view uses, open the view menu above the file list and choose **View details**. The **Highlight cells** section lists the rules of each column, in order. If you manage views, the menu item is **Edit …** instead.

## Which rule wins

Rules belong to one column and only look at the value in that column. A cell gets **at most one** highlight.

The rules of a column are checked **from top to bottom**. The first rule that matches decides the color and the name; the rules below it are not checked for that cell. The editor says so above the list: *When a cell matches more than one rule, the top one counts.*

**Example.** A *Deadline* column has three rules:

| # | Condition | Color | Name |
|---|---|---|---|
| 1 | Before today | Red | Overdue |
| 2 | Today | Orange | Due today |
| 3 | Within the next 7 days | Orange | Due soon |

A deadline of today matches rules 2 and 3, because *within the next 7 days* includes today. Rule 2 is higher, so the cell shows **Due today**. Swap rules 2 and 3, and every deadline of today shows **Due soon** instead. Put the most specific or most urgent rule at the top.

A few situations make a rule not count, or stop the check:

- **MetaVox cannot tell.** If a rule cannot be checked, MetaVox stops at that rule and leaves the cell without a highlight, rather than letting a rule further down color it. This happens with a value longer than 512 characters, which reaches the list cut off: only **Is empty** and **Is not empty** are decided for it; every other rule, the Checkbox rules included, cannot be. It also happens with a stored value that is not a date in a Date field, or not a number in a Number field.
- **Folders** have their own metadata and are highlighted like files, also when their value is empty: **Missing** marks a folder without a value.
- **Never highlighted:** a row whose values are still loading, and a folder from another storage shown inside the team folder, such as a nested team folder, which has no MetaVox values there.
- **The rule no longer fits the field.** If a field changes type, its rules can stop making sense. Such a rule highlights nothing and is skipped. The editor marks it: *This rule doesn't work with this field and highlights nothing.*

## Setting up highlights

You can add and change highlight rules if you may manage the views of the team folder. Those are the members who have write access to the team folder. Everyone else sees the rules under **View details**, read-only.

1. Open the team folder in the Files app.
2. Open the view menu above the file list and choose **Edit …**, or **New view** for a new one.
3. Go to the **Highlight cells** section. It lists every column that is **Visible** under **Columns**. Click a column to open it.
4. Click **Add rule for** *column* and choose a ready-made rule or **Custom rule**.
5. Choose a **Condition**, fill in a value if the condition asks for one, and pick a **Color**. Optionally add a **Text by the icon**.
6. **Preview** shows how the highlight will look and what it says. Click **Done**.
7. Click **Save** to save the view.

To change a rule, click it. To change the order or remove a rule, use the **⋯** menu next to it: **Move up**, **Move down** and **Delete**.

- A column can have up to **10 rules**.
- While a rule is open and not complete, **Save** is not available. Click **Done** to finish the rule, or **Cancel** to drop the change.
- Only visible columns can get rules. A column you hide **keeps** its rules, and they come back when you make it visible again. The editor lists those columns under *Hidden columns keep their rules*.

### Ready-made rules

| Rule | Field types | Condition | Color |
|---|---|---|---|
| Overdue | Date | Before today | Red |
| Due today | Date | Today | Orange |
| Due soon | Date | Within the next 7 days | Orange |
| Missing | All except Checkbox | Is empty | Orange |
| Checked | Checkbox | Is checked | Green |
| Not checked | Checkbox | Is not checked | Orange |
| Assigned to the person viewing | Account | Is the person viewing | Blue |

- Each ready-made rule can be added once per column. Afterwards you can change its condition, color and name like any other rule.
- Ready-made rules are placed in a sensible order for you: **Overdue**, **Due today**, **Due soon**, **Missing**. A custom rule goes to the bottom of the list; move it up if it should count first.
- **Assigned to the person viewing** is personal: each person sees the files assigned to them highlighted, with the name *Assigned to you*.

### Conditions per field type

| Field type | Conditions |
|---|---|
| Text, Textarea, URL | Is, Is not, Contains, Does not contain, Is empty, Is not empty |
| Number | Is, Is not, Greater than, Less than, At least, At most, Is empty, Is not empty |
| Date | Before today, Today, After today, Within the next … days, More than … days ago, Before a date, On a date, After a date, Is empty, Is not empty |
| Select (Dropdown) | Is, Is not, Is one of, Is empty, Is not empty |
| Multi-select | Contains one of, Contains all of, Is empty, Is not empty |
| Checkbox | Is checked, Is not checked |
| Account | Is the person viewing, Is empty, Is not empty |
| File Link | Is empty, Is not empty |

### How conditions compare

- **Text** is compared without regard to upper and lower case. Spaces before and after the value are ignored.
- **Empty cells.** *Is not*, *Does not contain* and *Is not checked* also match an empty cell. The other conditions, such as *Is*, *Contains*, *Greater than* and all date conditions, never do. To treat empty cells differently, put an **Is empty** rule above them.
- **Dates** are compared by day; a time of day is ignored. *Within the next 7 days* runs from today up to and including the seventh day from now. *More than 30 days ago* matches dates before the day 30 days ago. A number of days is a whole number from 1 to 3650.
- **Today** is the date on the computer of the person looking. Around midnight, two people in different time zones can see different highlights.
- **Numbers** are typed without thousands separators, for example `10000`. A decimal comma is accepted where your language writes one.
- **Options.** A rule on a Select or Multi-select field uses the text of the option. If an option is later removed from the field, the rule keeps it, and the editor notes: *This rule uses an option the field no longer has.*

### The text by the icon

**Text by the icon** is optional and can be up to 40 characters. It shows when someone points at the icon in a highlighted cell, so it is a good place for what needs to happen: *Approve first*, *Renew insurance*. Without it, a ready-made rule shows its own name (*Overdue*) and a custom rule shows what it checks (*before today*). A ready-made rule keeps its name only while its condition and value are unchanged.

The text is also listed under **View details** and read out by screen readers with the cell, so say what the state means or what to do, not the color.

## Good to know

- Highlight rules are saved with the view. They are not part of the MetaVox backup.
- Someone who already has the folder open sees changed rules after reloading the page.
- Integrations can read and write the rules through the API: see [Highlight rules in the API reference](../architecture/api-reference.md#highlight-rules-format).

## See Also

- [Views](views.md) - Switching views and filtering
- [Managing Views](../admin/views.md) - Creating and configuring views
- [Field Types](field-types.md) - Understanding metadata field types
