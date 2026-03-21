// completion-utils.js - Customer Profile Completion Tracking

// List of all fields to track (112 total)
const ALL_PROFILE_FIELDS = [
    // Personal Info (14 fields)
    'fullName', 'fatherName', 'motherName', 'husbandName', 'dob', 'age', 
    'gender', 'maritalStatus', 'religion', 'category', 'bloodGroup', 
    'nationality', 'subCategory', 'casteIssuedAuthority',
    
    // Contact (4 fields)
    'mobile', 'alternateMobile', 'email', 'landline',
    
    // Permanent Address (9 fields)
    'permHouseNo', 'permVillageTown', 'permPostOffice', 'permPoliceStation', 
    'permBlock', 'permDistrict', 'permState', 'permPinCode', 'fullAddress',
    
    // Correspondence Address (8 fields)
    'corrHouseNo', 'corrVillageTown', 'corrPostOffice', 'corrPoliceStation', 
    'corrBlock', 'corrDistrict', 'corrState', 'corrPinCode',
    
    // Identity Documents (5 fields)
    'aadhaarNumber', 'panNumber', 'voterID', 'drivingLicense', 'passportNumber',
    
    // 10th/Matriculation (8 fields)
    'tenth_board', 'tenth_school', 'tenth_rollNumber', 'tenth_year', 
    'tenth_marks', 'tenth_totalMarks', 'tenth_percentage', 'tenth_division',
    
    // 12th/Intermediate (8 fields)
    'twelfth_board', 'twelfth_school', 'twelfth_stream', 'twelfth_rollNumber', 
    'twelfth_year', 'twelfth_marks', 'twelfth_totalMarks', 'twelfth_percentage',
    
    // Graduation (8 fields)
    'grad_degree', 'grad_university', 'grad_college', 'grad_subject', 
    'grad_year', 'grad_cgpa', 'grad_percentage', 'grad_division',
    
    // Post-Graduation (6 fields)
    'pg_degree', 'pg_university', 'pg_subject', 'pg_year', 
    'pg_percentage', 'pg_cgpa',
    
    // Certificates (10 fields)
    'casteCertNumber', 'casteCertDate', 'incomeCertNumber', 'annualIncome', 
    'domicileCertNumber', 'ewsCertNumber', 'disabilityCertNumber', 
    'disabilityType', 'disabilityPercentage', 'pwd',
    
    // Employment (6 fields)
    'occupation', 'organizationName', 'designation', 'monthlyIncome', 
    'experience', 'employerAddress',
    
    // Bank (5 fields)
    'bankName', 'branchName', 'accountNumber', 'ifscCode', 'accountType',
    
    // Family (5 fields)
    'fatherOccupation', 'fatherIncome', 'motherOccupation', 
    'numBrothers', 'numSisters',
    
    // Bihar Specific (8 fields)
    'registryNumber', 'khatiyanNumber', 'khataNumber', 'plotNumber',
    'ticketType', 'serviceType', 'applicationType', 'dataEntryOperator'
];

// Critical fields (must have for basic forms)
const CRITICAL_FIELDS = [
    'fullName', 'fatherName', 'mobile', 'dob', 'gender',
    'aadhaarNumber', 'permDistrict', 'permState', 'permPinCode',
    'tenth_board', 'tenth_year', 'twelfth_board', 'twelfth_year'
];

// Important fields (needed for most forms)
const IMPORTANT_FIELDS = [
    'motherName', 'email', 'category', 'permHouseNo', 'permVillageTown',
    'permPostOffice', 'tenth_rollNumber', 'tenth_percentage',
    'twelfth_rollNumber', 'twelfth_percentage', 'grad_degree', 'grad_year'
];

/**
 * Calculate profile completion percentage
 * @param {Object} profileData - Customer profile data
 * @returns {Object} - { percent, total, filled, empty, criticalMissing, importantMissing }
 */
function calculateCompletion(profileData) {
    if (!profileData) {
        return {
            percent: 0,
            total: ALL_PROFILE_FIELDS.length,
            filled: 0,
            empty: ALL_PROFILE_FIELDS.length,
            criticalMissing: [...CRITICAL_FIELDS],
            importantMissing: [...IMPORTANT_FIELDS]
        };
    }
    
    let filledCount = 0;
    const criticalMissing = [];
    const importantMissing = [];
    
    // Count filled fields
    ALL_PROFILE_FIELDS.forEach(field => {
        const value = profileData[field];
        if (value !== null && value !== undefined && String(value).trim() !== '') {
            filledCount++;
        }
    });
    
    // Check critical missing fields
    CRITICAL_FIELDS.forEach(field => {
        const value = profileData[field];
        if (!value || String(value).trim() === '') {
            criticalMissing.push(field);
        }
    });
    
    // Check important missing fields
    IMPORTANT_FIELDS.forEach(field => {
        const value = profileData[field];
        if (!value || String(value).trim() === '') {
            importantMissing.push(field);
        }
    });
    
    const percent = Math.round((filledCount / ALL_PROFILE_FIELDS.length) * 100);
    
    return {
        percent: percent,
        total: ALL_PROFILE_FIELDS.length,
        filled: filledCount,
        empty: ALL_PROFILE_FIELDS.length - filledCount,
        criticalMissing: criticalMissing,
        importantMissing: importantMissing
    };
}

/**
 * Get field label for display
 * @param {string} fieldKey - Field key
 * @returns {string} - Human readable label
 */
function getFieldLabel(fieldKey) {
    const labels = {
        fullName: "Full Name",
        fatherName: "Father's Name",
        motherName: "Mother's Name",
        dob: "Date of Birth",
        mobile: "Mobile Number",
        email: "Email",
        aadhaarNumber: "Aadhaar Number",
        tenth_board: "10th Board",
        tenth_rollNumber: "10th Roll Number",
        tenth_year: "10th Year of Passing",
        tenth_percentage: "10th Percentage",
        twelfth_board: "12th Board",
        twelfth_rollNumber: "12th Roll Number",
        twelfth_year: "12th Year of Passing",
        grad_degree: "Graduation Degree",
        grad_year: "Graduation Year",
        panNumber: "PAN Number",
        bankName: "Bank Name",
        accountNumber: "Account Number"
    };
    
    return labels[fieldKey] || fieldKey.replace(/([A-Z])/g, ' $1').replace(/_/g, ' ').trim();
}

/**
 * Update completion UI in customer-profile.html
 * Should be called whenever form data changes
 */
function updateCompletionUI() {
    const form = document.getElementById('customerProfileForm');
    if (!form) return;
    
    // Get form data
    const formData = new FormData(form);
    const profileData = {};
    
    for (const [key, value] of formData.entries()) {
        profileData[key] = value;
    }
    
    // Also check input values directly (for fields not in FormData)
    const allInputs = form.querySelectorAll('input, select, textarea');
    allInputs.forEach(input => {
        if (input.id && !profileData[input.id]) {
            profileData[input.id] = input.value;
        }
    });
    
    // Calculate completion
    const completion = calculateCompletion(profileData);
    
    // Update progress bar
    const progressBar = document.getElementById('profileCompletionBar');
    const percentText = document.getElementById('completionPercentText');
    const statsText = document.getElementById('completionStats');
    
    if (progressBar) {
        progressBar.style.width = completion.percent + '%';
        progressBar.setAttribute('aria-valuenow', completion.percent);
        
        // Color based on completion
        progressBar.className = 'progress-bar';
        if (completion.percent >= 80) {
            progressBar.classList.add('bg-success');
        } else if (completion.percent >= 50) {
            progressBar.classList.add('bg-info');
        } else if (completion.percent >= 20) {
            progressBar.classList.add('bg-warning');
        } else {
            progressBar.classList.add('bg-danger');
        }
    }
    
    if (percentText) {
        percentText.textContent = completion.percent + '%';
    }
    
    if (statsText) {
        statsText.textContent = `${completion.filled}/${completion.total} fields filled`;
    }
    
    return completion;
}

// Export functions for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        calculateCompletion,
        getFieldLabel,
        updateCompletionUI,
        ALL_PROFILE_FIELDS,
        CRITICAL_FIELDS,
        IMPORTANT_FIELDS
    };
}
