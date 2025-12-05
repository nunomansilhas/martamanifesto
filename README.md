# MartaManifesto

A clear, human-readable place for the "Marta Manifesto" — the text, context, and resources that define the project's goals, principles, and how people can read, use, and contribute to it.

This repository contains the manifesto content (Markdown), any associated assets (images, sources), and tooling used to preview or publish the manifesto.

## Table of contents

- [About](#about)
- [Project structure](#project-structure)
- [Quick start](#quick-start)
- [Preview locally](#preview-locally)
- [Editing the manifesto](#editing-the-manifesto)
- [Publishing](#publishing)
- [Contributing](#contributing)
- [License](#license)
- [Authors & contact](#authors--contact)
- [Acknowledgements](#acknowledgements)

## About

MartaManifesto is intended to collect and present the manifesto content in a clear, versioned repository. Use this repo to:

- Store the canonical manifesto text.
- Track history and edits.
- Preview and publish the manifesto as a static page or PDF.
- Coordinate contributions and improvements.

If the manifesto has a specific audience, tone, or publishing target (website, printed pamphlet, slide deck), add those details here.

## Project structure

A suggested layout — adapt to your repo's actual layout:

- README.md — (this file) project overview and instructions
- manifesto/ — primary manifesto Markdown files
  - manifesto/README.md (main manifesto content)
  - manifesto/appendices/ (any supplementary material)
- assets/ — images, logos, fonts
- scripts/ — small scripts for building or publishing (optional)
- docs/ — generated site or published artifacts (if any)

Adjust the above to match how you've organized your repository.

## Quick start

Clone the repository:

git clone https://github.com/nunomansilhas/martamanifesto.git
cd martamanifesto

Open the manifesto Markdown file in your editor of choice (e.g., VS Code) and start editing.

## Preview locally

Choose one of the simple approaches below depending on your tooling preference.

Option A — Use VS Code or another editor that renders Markdown:
- Open the repository in VS Code and use the built-in Markdown preview (Ctrl/Cmd+Shift+V).

Option B — Use a lightweight tool (Python) to serve static files:
- If you have a single HTML or generated site in `docs/`, run:
  - Python 3: `python -m http.server 8000` from the `docs/` directory
  - Then open http://localhost:8000

Option C — Use a Markdown previewer:
- Install `grip` for GitHub-flavored Markdown preview:
  - `pip install grip`
  - `grip manifesto/README.md`
  - Open the served URL in your browser.

Option D — If you're using a static site generator (Hugo/Jekyll):
- Follow the generator's standard local preview steps. If you'd like, I can add a minimal site scaffold.

## Editing the manifesto

- Edit the primary Markdown file(s) under `manifesto/`.
- Keep edits atomic: one idea or fix per commit with a descriptive message.
- Use meaningful commit messages, e.g., "Clarify principle 2 — accessibility".

If you want a suggested editing checklist to reduce friction for reviewers, I can add one.

## Publishing

Decide how you want the manifesto published:
- As a standalone Markdown file on GitHub (no extra steps).
- As a static site (GitHub Pages) — push generated site to `gh-pages` or use the `docs/` folder and enable Pages in repository settings.
- As a PDF — generate from Markdown using Pandoc or other tools:
  - Example: `pandoc manifesto/README.md -o manifesto.pdf --pdf-engine=xelatex`

If you'd like a CI workflow to automatically build and publish on push, I can create a GitHub Actions workflow for that.

## Contributing

Contributions are welcome. A simple workflow:

1. Fork the repository.
2. Create a feature branch: `git checkout -b fix/typo-principle-1`
3. Make changes and commit with a clear message.
4. Push to your fork and open a Pull Request against `main`.

Optionally add a CONTRIBUTING.md in the repo to describe:
- Style and tone guidelines for the manifesto.
- Review expectations.
- How to open issues for discussion.

Would you like me to create a CONTRIBUTING.md with suggested guidelines?

## License

Add a license that matches how you want the manifesto to be used (for example, CC BY-SA, CC BY, or a permissive software license if there is code). Example placeholder:

This repository's content is available under [INSERT LICENSE]. See LICENSE for details.

If you tell me the desired license (e.g., "CC BY-SA 4.0"), I can add a LICENSE file.

## Authors & contact

- Author: nunomansilhas
- Repository: https://github.com/nunomansilhas/martamanifesto

For questions or contributions, open an issue or send a PR.

## Acknowledgements

Thanks to everyone who contributes ideas, edits, and proofreading.

---

If you'd like, I can:
- Commit this README.md to the repository.
- Populate the manifesto/ folder with the current manifesto text (if you provide it).
- Add a CONTRIBUTING.md, LICENSE, and a GitHub Actions workflow to build/publish the site automatically.
Tell me which next step you want and I'll proceed.
