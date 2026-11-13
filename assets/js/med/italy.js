/**
 * EverShelf Med — the ITALY module (browser side). Country code, kept out of the core.
 *
 * What is Italian here, and nowhere else:
 *   1. the number that identifies the drug is the **AIC**, and the box PRINTS it —
 *      `A040313076` in Code 39, or the bare 9 digits of the older Code 32 form;
 *   2. the 2D DataMatrix of the EU FMD carries it as the national reimbursement number
 *      (AI **710**), next to the lot the scanner must not mistake for a drug code;
 *   3. the SSN label (`Pharmacode`/`Laetus`) and any short alphanumeric are PACKAGING — the
 *      schema lists them under `ignored` with `action: "ocr"`, because the AIC is printed and
 *      has to be read off the label.
 *
 * The core (`assets/js/core/med.js`) knows none of this: it walks the GS1 string, applies the
 * schema's `codes`/`ignored`/`ocr` lists and asks this module only "is this read one of YOUR
 * codes?". A new country = a block in `data/medical_code_schemas.json` (`"module": "<slug>"`)
 * plus a file like this one that calls `_medRegisterCountryModule()`.
 *
 * Registered by the slug the schema declares (`"module": "italy"`), never by the country code:
 * the data file decides which module a country uses.
 */

/** The AIC of a read, in its canonical 9-digit form, or null ("this is not an AIC"). */
function _medItalyAicFromCode(code) {
    const c = _medCompactCode(code);
    if (!c) return null;
    let m = /^A(\d{9})$/.exec(c);
    if (m) return m[1];
    // NO bare 9-digit form: the label's second Code 39 carries the pack's progressive number
    // (`014058142`), which looks like an old Code 32 AIC. The printed block has the A.
    // The FMD DataMatrix: the AIC is the national reimbursement number (AI 710), possibly
    // zero-padded by the encoder. The lot (AI 10) is never a drug code.
    const gs1 = _medGs1Parse(code);
    const nhrn = String((gs1.ais && gs1.ais['71']) || '');
    if (nhrn) {
        const aic = /^0*(\d{9})$/.exec(nhrn.toUpperCase());
        if (aic) return aic[1];
    }
    return null;
}

/**
 * Italian PARAF / EAN for parafarmaci and medical devices (Farmadati Italia Srl).
 *
 * High-confidence shape only: exactly 9 digits starting with `9` (optional leading `A` stripped).
 * These are NOT AIFA AICs and never belong in `localCode()`.
 *
 * @param {string} read
 * @returns {{code:string,type:string,label_key:string}|null}
 */
function _medItalyParafFromCode(code) {
    const c = _medCompactCode(code);
    if (!c) return null;
    const digits = /^A?(\d{9})$/.exec(c);
    if (!digits) return null;
    const nine = digits[1];
    if (nine[0] !== '9') return null;
    return { code: nine, type: 'paraf', label_key: 'medical.code_paraf' };
}

_medRegisterCountryModule('italy', {
    cc: 'IT',

    /**
     * Is this read the Italian drug number?
     *
     * @param {string} read the whole barcode/OCR read
     * @returns {{code:string,type:string,label_key:string,aic:string}|null}
     *          `code` is what the box prints (`A` + 9), `aic` the 9 digits the catalogue is
     *          keyed by.
     */
    localCode(read) {
        const aic = _medItalyAicFromCode(read);
        if (!aic) return null;
        return { code: 'A' + aic, type: 'aic', label_key: 'medical.code_aic', aic: aic };
    },

    /**
     * Is this read an Italian Farmadati PARAF/EAN (9xxxxxxxx)?
     *
     * @param {string} read
     * @returns {{code:string,type:string,label_key:string}|null}
     */
    parafCode(read) {
        return _medItalyParafFromCode(read);
    },
});
