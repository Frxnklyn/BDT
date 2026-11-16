# BDT: Behavior Drive Testing for pages

## Preparing an installation for testing

1. Install prerequisites - see [setup guidelines](Setup/index.md) if not
2. Got to `Administration > Automated tests`. The system will set up everything automatically

## Running a test

Run commands from the installation root, not from the `vendor` directory.
Initialize Behat once with `vendor/bin/action axenox.BDT:Behat init` (or open
`Administration > Automated tests`). Configure `PARALLEL.CHROME_PATH` in the
BDT app configuration with the actual `chrome.exe` path, not a portable launcher.
See the [setup guidelines](Setup/index.md) for browser and authentication prerequisites.

The included Core smoke test logs in as a temporary test user with the
`exface.Core.SUPERUSER` role, opens the Core logs page and checks that its
`DataTable` is present:

```console
vendor/bin/action axenox.BDT:RunTest --feature=vendor/axenox/bdt/Tests/Behat/Features/Administration/core-smoke.feature --tags=@smoke-test
```

On Windows use `vendor\bin\action.bat`, or prefix `vendor/bin/action` with the
path to your PHP CLI executable. Behat must include the BDT app suite.

If Behat reports that `axenox\BDT\Behat\Extension\BehatExceptionExtension`
could not be located although its file exists, check the installed package's
Composer PSR-4 prefix: it must be `axenox\\BDT\\`, without a leading backslash.
After installing a corrected package, regenerate the installation's autoloader
with `php composer.phar dump-autoload --no-scripts` from the installation root.
Regeneration alone does not correct an old prefix in Composer's installed
package metadata.

`--feature` accepts a feature file or directory relative to the installation
root. `--tags` applies a Behat tag expression within that selection. `--suite`
is also supported. At least one selector is mandatory to avoid an accidental
run of the entire test base. `--behat_config` overrides the base configuration
(default: installation-root `behat.yml`), and `--chrome_path` overrides the
configured browser path. The action allocates an isolated Chrome port/profile.
For `RunTest`, relative Chrome paths from either `--chrome_path` or
`PARALLEL.CHROME_PATH` resolve against the installation root, independently of
the caller's working directory. Absolute paths remain absolute, and an explicit
`--chrome_path` still takes precedence over the configured value.

Read the Behat scenario/step summary and the closing status banner. A returned
action is not proof of passing tests: failures and crashes are reported in its
output, and zero selected scenarios do not verify anything.

### Viewing a test-run report

BDT registers its Markdown printers with Core's component registry. Open
`/api/docs/exface/core/Docs/understanding_the_metamodel/Model_components.md?component=bdt_run&selector=...`.
Pass a run UID or a single Gherkin tag such
as `@smoke-test`; a tag selects the newest matching run. HTML rendering and
styling use the Core DocsFacade template and Markdown renderer. Both components
use Core's registry-based `ComponentPrinterMiddleware` directly; BDT provides
no custom HTTP facades, route adapters or rendering middleware.
Keep the installation's dependencies aligned with Core: the renderer requires
`league/commonmark` (`^2.10.2`), and the documentation template requires
`npm-asset/github-markdown-css` (`^5.9`), including `github-markdown-light.css`.
The route also responds with raw Markdown when requested with `Accept: text/markdown`. The
report shows run metadata, result totals, and every recorded feature, scenario,
and step, including step details and errors.
Recorded screenshots are embedded under their step and linked to their full-size
image through Core's file-object endpoint (`axenox.BDT.run_step_screenshot`).
File UIDs use Base64URL encoding so Apache does not reject encoded path separators.
Only PNG paths inside `data/axenox/BDT/Screenshots` are accepted; access continues
to use the file facade's authentication and authorization.
Log IDs link to the Core logs page and its existing `showLogId` dialog.
Historical logs may have been removed by retention even when a run still contains
their IDs. Recorded page URLs are omitted to avoid redundant navigation.
Step diagnostics and screenshots are grouped in native HTML disclosures, open
initially for failed, timed-out or undefined steps. Other step details start
collapsed. Status badges use green for passed, red for failed/timeout/undefined,
and neutral colors otherwise, always retaining the localized text.

`BdtRunMarkdownPrinter::getFiles(): array` returns unique screenshot attachments
as Core `FileInfoInterface` objects, matching GenAI message attachments and
`AiToolResultString::withAttachments($printer->getFiles())`. These are file
objects, not URLs or encoded strings; contents are read only when needed.
Markdown and files share one loaded run snapshot (including tag resolution).
Runs without screenshots return `[]`; invalid references or missing screenshot
files raise an exception rather than silently dropping attachments. Log IDs
remain links and are not exported as files. Calling `getFiles()` alone works
without calling `getMarkdown()` first.
The report title comes from the run object's name. Field captions, including
statistics and step details, use the corresponding attributes' metamodel names
instead of printer-specific labels. Metadata and statistics are rendered as
two-column tables; the first column retains the model-provided attribute names.
The printer embeds the two summary tables as HTML in a responsive grid: side by
side on wide screens and stacked on narrow screens. This HTML is part of the
Markdown output; no facade or shared stylesheet customization is needed.
Table headings, status labels and empty-report messages use
the BDT app translator with the current session language (German or English,
with the standard English fallback). Object and attribute captions continue
to use model translations where available; recorded test texts are not translated.

Each feature heading links directly to its registered `bdt_feature` component,
without a separate file-path table or "Open feature report" label, through the
Core DocsFacade component route.
For example:
`/api/docs/exface/core/Docs/understanding_the_metamodel/Model_components.md?component=bdt_feature&selector=axenox%2Fbdt%2FTests%2FBehat%2FFeatures%2FAdministration%2Fcore-smoke.feature`.
This printer reads the current `.feature` file using a vendor-relative path,
independently of recorded test runs. Absolute paths, traversal and files outside
the vendor directory are rejected. Run and feature have independent
printers, sharing the Core DocsFacade and registry-driven rendering pipeline.
Both component reports require authentication and support the
session language and `Accept: text/markdown`.

### Registered BDT components

BDT owns two component types, `bdt_run` and `bdt_feature`. Their selector classes,
factories and independent instance printers are declared in
`Config/ComponentRegistry.config.json`. Core's standard `ComponentRegistryInstaller`
automatically merges this app fragment into the installation registry during
BDT installation/update and removes BDT's entries on uninstall. No BDT-specific
Core registration code or manual registry edits are required.

The registry creates a `BdtRun` (UID or scenario tag) or `BdtFeature`
(vendor-relative file path). Its registered printer implements
`MarkdownInstancePrinterInterface`; Core calls `constructForInstance()` and
`getMarkdown()`. The components are also accessible directly through DocsFacade:

- `/api/docs/exface/core/Docs/understanding_the_metamodel/Model_components.md?component=bdt_run&selector=0x11f1bc127645094cbc12025041000001`
- `/api/docs/exface/core/Docs/understanding_the_metamodel/Model_components.md?component=bdt_feature&selector=axenox%2Fbdt%2FTests%2FBehat%2FFeatures%2FAdministration%2Fcore-smoke.feature`

### Chrome startup and login diagnostics

Chrome process discovery matches TCP listening endpoints rather than translated
`netstat` state names, supporting localized Windows output and IPv4/IPv6.
Failure to resolve a ready browser's PID is reported as a startup error, not a
successful launch with an invalid process ID.

The UI5 test context explicitly selects the UI5 facade and a `ShowWidget` page
task during navigation. This also renders the UI5 login application when the
installation uses another default facade, such as jEasyUI. No system-wide facade
change is needed. HTTP error detection stays enabled: only pages recognized as
UI5 applications proceed to UI5 initialization, and a plain HTTP 401 error page
is still rejected. Do not disable authentication or globally ignore HTTP 401 to
make a login scenario pass.

## Testing with an AI agent

For the assigned Jira ticket workflow (Ready, Implementing and Testing),
including automatic Gherkin tag association and test execution, see the
[implementation workflow and diagram](ImplementationWorkflow.md).

BDT provides two independent persisted skills in `Model/AI/Skills`:

- `axenox.BDT.GherkinReadWrite`: the unchanged branch skill for reading,
	authoring and validating feature files. It has no execution tool.
- `axenox.BDT.GherkinTestRunner`: discovers and runs existing features without
	write or patch tools. Its tools are `list_test_features`,
	`search_gherkin_files`, `read_test_feature` and `run_gherkin_test`.

The included agent `axenox.BDT.gherkin_test_assistant:1.0` combines both through
regular `AI_AGENT_VERSION_SKILL` assignments, not embedded skill definitions.
The shared search tool is deduplicated by GenAI, leaving nine effective tools.
The agent and its test cases are exported in
`Model/AI/gherkin_test_assistant`. Import BDT AI model changes before use.
Assign a local LLM connection to the agent version in GenAI administration;
the portable export intentionally does not reference an installation-specific
connection. Without one, agent evaluation cannot run.

`run_gherkin_test` reuses `axenox.GenAI.CallActionTool` with the predefined
`axenox.BDT.RunTest` action. Its arguments are `feature` (an existing feature
file with the `vendor/` prefix) and `tags` (a tag expression or an empty
string). Discovery/editing paths are vendor-relative instead, without the
`vendor/` prefix. No arbitrary shell command or infrastructure override is
exposed by this tool. Tests run with the installation's configured prerequisites.

The execution skill instructs the agent to discover unknown tests before selecting them,
run only a requested feature, inspect the actual output and distinguish failed
scenarios from missing prerequisites or crashes. Listing tests alone does not
authorize execution or editing. Scope and environment authorization still
matter: browser tests may create users or change application data, so use a
test installation rather than production.

### Evaluating the agent

Six persisted `axenox.GenAI.AI_TEST_CASE` records exercise the combined agent:

| Test case | Expected behavior |
| --- | --- |
| Execute an explicitly named smoke test | Read and run `core-smoke.feature` once with `@smoke-test`. |
| Infer the Core administration test from behavior | Discover the matching feature from the administrator/log/table description, read it and run it once. |
| List matching tests without executing them | Find and read the feature without running or editing it. |
| Do not invent a missing test | Search and report absence; no substitute execution or file creation. |
| Reject an absent tag instead of broadening scope | Read the feature, report the mismatch and stop. |
| Validate Gherkin without browser execution | Use the original skill's read and strict-validation tools only. |

Select one case at a time on the GenAI testing page and invoke
`axenox.GenAI.RunTest`. This is the **agent evaluation** action, distinct from
the **browser execution** action `axenox.BDT.RunTest` used by the tool.
Use a fresh action invocation for each case: the current GenAI action caches
the first case's prompt and agent, so a multi-case batch is not a reliable
evaluation of different prompts.

Each case has a persisted `AI_TEST_CRITERION` with existing GenAI `ToolCheck`
and `TextMatch` metrics. They check actual tool usage, forbidden edits and,
where applicable, exact feature/tag values and discovery. BDT registers these
criteria with its AI installer so they survive model import/export.
Require **5/5 for every metric**, rather than accepting an average that masks
a forbidden action. Inspect `AI_TEST_RUN`, `AI_TEST_RESULT` and
`AI_TEST_RESULT_RATING`, not just the action's completion message.

These automated scores measure tool selection and arguments, not every aspect
of answer quality. Also review the final answer against the real tool output:
correct scenario/step counts, honest pass/fail or infrastructure reporting,
a reproducible CLI command, no unnecessary clarification for a unique match,
and complete strict-validation input where requested. A correctly selected
browser test can still fail due to the environment; that is not automatically
an agent-selection failure. Real evaluations consume LLM requests, and the
first two cases launch Chrome and may provision temporary test users. Run them
only on an authorized test installation. Synthetic metric checks do not prove
that an LLM can solve these prompts.

## Developing Behat contexts and steps

### Write tests

- [Introduction to the Gherkin language](Introduction/Writing_tests_-_Gherkin_language.md)

### Debug a test

You will need a local workbench installation as described in the [installation guied](https://github.com/ExFace/Core/blob/1.x-dev/Docs/Installation/index.md) and a PHP IDE like Visual Studio Code with PHP debugging set up correctly.

1. Open your favorite command line: e.g. Windows `cmd`;
2. Navigate to the workbench installation folder: e.g. `cd c:\wamp\www\exface\exface`
3. Make sure xDebug is set to debug CLI commands: e.g. set environment variables via `set XDEBUG_MODE=debug& set XDEBUG_SESSION=1`.
4. Run the Behat suite or scenario you wish to debug: e.g. `vendor\bin\Behat --suite=ui5.test`