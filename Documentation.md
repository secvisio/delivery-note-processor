# Delivery-Note Scanner — User Guide

A guide for office staff. No technical knowledge required.

---

## 1. Overview

### What the system does

The delivery-note scanner takes scanned delivery notes (PDFs or images) and **automatically**:

- reads the text on the page,
- finds the **delivery-note number** and the **company name**,
- renames the file using a clear, searchable name,
- sorts the file into the right folder.

You scan once. The system handles everything else.

### What problem it solves

Without the scanner, every paper delivery note has to be:

- given a meaningful filename by hand,
- filed in the correct folder,
- looked up later by remembering where it was saved.

This is slow and error-prone. The scanner does it for you in seconds, and the filename always follows the same pattern,
so finding a delivery note later is just a matter of typing the number into a search box.

---

## 2. How to use the system

You only ever do **one thing**: scan the delivery note on the office scanner.

The office scanner is already configured to deliver every scan straight to the network drive into the system's incoming
folder. Once the scanner has finished, the system picks the new file up automatically.

That is all the action required from you. You do not drag, drop, copy, upload, or rename anything. From the moment the
scan arrives, the system takes over.

### What happens after that — automatic

You do not need to click anything else. In the background:

1. The system reads the page (this is called **OCR** — optical character recognition).
2. It looks for the **delivery-note number** and the **company name**.
3. It judges **how confident** it is in what it found.
4. It picks a new filename based on the result.
5. It saves the renamed file into the correct folder.
6. The original scan is also kept, untouched, for safekeeping.

Most documents are processed within a minute. Larger or denser pages may take longer.

### How to check the result

Open the **Process Log** page in the web application. There you can see:

- which documents are being processed right now,
- which ones finished successfully,
- which ones failed and why,
- the OCR text that was read from each page.

---

## 3. File naming explained

Successfully recognized files always follow this pattern:

```
ls_<delivery-note-id>_<company-name>.pdf
```

- **ls** stands for *Lieferschein* (delivery note). It is always the first part of the name, so you can recognize a
  delivery note at a glance.
- **delivery-note-id** is the number printed on the document.
- **company-name** is the name of the company that sent the delivery note, written in lowercase with underscores instead
  of spaces.
- The file extension (`.pdf`, `.jpg`, …) is kept the same as the original scan.

### Examples

| Real document                                   | Filename produced                     |
|-------------------------------------------------|---------------------------------------|
| Delivery note 2450975 from Carl Ostermann Erben | `ls_2450975_carl_ostermann_erben.pdf` |
| Delivery note A-9981 from Müller GmbH           | `ls_a_9981_müller_gmb_h.pdf`          |
| Delivery note 12-04-22 from ACME Corp           | `ls_12_04_22_acme_corp.pdf`           |

### Why this pattern is useful

- **Sortable**: all delivery notes start with `ls_`, so they group together in any folder listing.
- **Searchable**: type the delivery-note number into Windows Explorer's search and you find the file instantly, no
  matter who sent it.
- **Predictable**: every name is built the same way, with no spaces and no special characters.

### Special filenames you may see

- `ls_xxxxxx_<company>_<timestamp>.pdf` — the company name was recognized, but the delivery-note number could not be
  read with confidence. The timestamp keeps the filename unique. (See section 5.)
- `ls_<id>_xxxxxx.pdf` — the number was recognized, but not the company name.
- `ls_xxxxxx_xxxxxx_<timestamp>.pdf` — neither could be read confidently.

`xxxxxx` is the placeholder used to mark "could not be read." Whenever you see it in a filename, the document needs a
human to look at it.

---

## 4. Folder structure explained

There are two result folders the system uses. They are both under the shared network drive. (Scans first arrive in an
internal incoming folder that the system watches; you do not need to work in it directly — use the Process Log instead.)

### `Lieferscheine` — Successfully recognized delivery notes

- The system places **renamed** delivery notes here when it is confident about both the number and the company.
- Files in this folder follow the clean naming pattern (`ls_<id>_<company>.pdf`).
- This is the folder you usually want to look in when searching for a specific delivery note.

### `Nicht zugeordnet` — Failed recognition

- The system places renamed delivery notes here when it could **not** recognize the number, the company, or both with
  enough confidence.
- These files still get a name, but the unrecognized parts are filled in with `xxxxxx`.
- These need a person to check the file, work out what the correct values are, and either rename them by hand or scan
  them again at higher quality.

---

## 5. What happens in error cases

The system uses a **confidence score** for both the delivery-note number and the company name. If the score is too low,
the system treats it as "not recognized" and moves the file to `Nicht zugeordnet`.

### Missing or unreadable delivery-note number

- The file is saved as `ls_xxxxxx_<company>_<timestamp>.pdf`.
- It lands in `Nicht zugeordnet`.
- The original scan can be viewed from the Process Log.
- **What to do**: open the original, find the number on the page, rename the file by hand, and move it to
  `Lieferscheine`.

### Missing or unreadable company name

- The file is saved as `ls_<id>_xxxxxx.pdf`.
- It also lands in `Nicht zugeordnet`.
- **What to do**: same as above — open the original and correct the name.

### Completely unreadable scan

- If the page cannot be read at all (blank, very blurry, all text rotated upside down, etc.), no number and no company
  can be extracted.
- The file gets a placeholder name and is filed in `Nicht zugeordnet`.
- The Process Log will show the document with status **failed** or with an empty OCR result, so you can see at a glance
  which scans need attention.

### Important: nothing is lost

Even when recognition fails:

- the renamed copy (with placeholders) is filed in `Nicht zugeordnet`,
- the Process Log keeps a record with the time, the read text, the original scan, and any error message.

---

## 6. Common issues & solutions

### "The file did not show up in `Lieferscheine` or `Nicht zugeordnet`"

- Wait a minute. Recognition can take a moment, especially for multi-page or high-resolution PDFs.
- Check the **Process Log**. If the document is listed as *running*, it is still being worked on.
- If it is listed as *failed*, the message will tell you what went wrong.
- If it is not listed at all, the file may not have been picked up — scan the document again on the office scanner.

### "The delivery-note number is wrong"

- The OCR may have read a similar-looking character (for example `O` instead of `0`, or `I` instead of `1`).
- Open the original from the Process Log, confirm the correct number, and rename the file by hand.
- If this happens often with the same supplier, see the tips in section 7.

### "The company name is recognized but with strange splits"

- For example: a company called *ACME GmbH* might appear as `acme_gmb_h`.
- This happens because the system splits names according to capital letters in the original text.
- The file is still correct — the underscore split does not lose any information. You can rename it manually if a
  cleaner name is needed.

### "I scanned the same document twice"

- The system does **not** automatically detect duplicates. The second scan is processed exactly like the first.
- If both produce the same recognized number and company, both end up in `Lieferscheine`. The newer one will overwrite
  the older one if the filename is identical.
- For unrecognized scans (in `Nicht zugeordnet`), each gets a unique timestamp at the end of its name, so they do **not
  ** overwrite each other.
- **Best practice**: if you suspect a duplicate, check `Lieferscheine` for an existing file with that number before
  re-scanning.

### "The system seems slow"

- This usually means many documents arrived at once and are being worked through one or two at a time. This is
  intentional — it stops the server from being overloaded.
- The queue clears on its own. Watch the Process Log if you need to see when your specific document is done.

---

## 7. Tips for best results

The system is only as good as the scan it gets. A few simple habits make a big difference.

### Scanning

- **Resolution**: scan at **300 DPI**. Lower than that, OCR struggles. Much higher than that, files become huge with no
  benefit.
- **Black & white** is fine for clean printed paper. Use **greyscale** if the original has watermarks or faint stamps.
- **Straighten the paper** in the feeder. Skewed scans confuse OCR.
- **One delivery note per file** if possible. Multi-document PDFs work, but only the first page is scanned for the
  number and company.
- Make sure the **delivery-note number is on the first page**. If a supplier prints it only on the last page, scan that
  page first.

### Paper quality

- Avoid scans of **wrinkled** or **stained** documents.
- **Faxed** delivery notes scanned again often have very poor quality — re-request the original PDF if possible.
- **Highlighter pen** over the delivery-note number can confuse OCR. Try not to mark the number itself.

### File handling

- **Do not edit** files inside `Lieferscheine` or `Nicht zugeordnet` while the system is processing them. Wait until the
  Process Log shows them as *finished*.
- If you need to **redo** a document (for example because the recognition was wrong), simply scan it again on the office
  scanner. The system will create a new entry; the old one stays in the Process Log for reference.

### When the system is unsure

- A file in `Nicht zugeordnet` is **not a system bug** — it is the system being honest about not being sure. Treat the
  folder as a small to-do list: open each file, fill in the missing piece, and move it to `Lieferscheine`.
- If the **same supplier** keeps ending up in `Nicht zugeordnet`, their delivery notes may have an unusual layout.
  Mention this to IT — the recognition prompt can be adjusted.

---

## Quick reference

| You see…                                                    | What it means                                          | What to do                                           |
|-------------------------------------------------------------|--------------------------------------------------------|------------------------------------------------------|
| File in `Lieferscheine` with a clean name                   | Fully recognized                                       | Nothing — done                                       |
| `ls_xxxxxx_<company>_<timestamp>.pdf` in `Nicht zugeordnet` | Number not read                                        | Look up the number, rename, move to `Lieferscheine`  |
| `ls_<id>_xxxxxx.pdf` in `Nicht zugeordnet`                  | Company not read                                       | Look up the company, rename, move to `Lieferscheine` |
| `ls_xxxxxx_xxxxxx_<timestamp>.pdf` in `Nicht zugeordnet`    | Nothing recognized                                     | Check scan quality; rescan if needed                 |
| Document in Process Log as *running*                        | Just arrived from the scanner; processing in progress  | Wait, then check Process Log                         |
| Process Log shows *failed*                                  | Something went wrong (often: blank page or scan error) | Open the original, rescan if needed                  |
